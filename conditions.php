<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * TODO describe file conditions
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// TODO Refactor this file.

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\rule_component_loader;

require('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$ruleid = required_param('ruleid', PARAM_INT);
$type = optional_param('type', '', PARAM_TEXT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/coursedynamicrules:managecondition', $context);

$url = new moodle_url('/local/coursedynamicrules/conditions.php', ['courseid' => $courseid, 'ruleid' => $ruleid]);
$rulesurl = new moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $courseid]);

$PAGE->set_title($course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

if (!\local_coursedynamicrules\helper\ownership::rule_belongs_to_course($ruleid, $courseid)) {
    throw new moodle_exception('invalidruleid', 'local_coursedynamicrules');
}

// Build and process the edit/create form BEFORE any output is echoed: a cancelled or submitted
// form redirects, and redirect() cannot run after $OUTPUT->header() has already been sent.
$editid = optional_param('edit', 0, PARAM_INT);
$conditioninstance = null;
if ($editid > 0) {
    // Ownership is checked here (GET) and re-checked implicitly on POST: $editid comes from
    // optional_param() again on the resubmitted request, and the row is only writable through
    // save_condition() -> upsert(), which never touches ruleid. get_condition() enforces that the
    // component belongs to BOTH the requested course AND the requested rule, so a tampered hidden
    // id (foreign course, or a different rule in the same course) is rejected before any form
    // render or DB write.
    $conditionrecord = \local_coursedynamicrules\helper\ownership::get_condition($editid, $courseid, $ruleid);
    $conditioninstance = rule_component_loader::create_condition_instance($conditionrecord, $courseid);

    // json_decode() returns a PHP array (not stdClass) for a JSON array such as "[]", which
    // `empty()`-based edit-mode checks in the form base classes would treat as "no record"
    // (empty() is only ever false for objects, so a cast is enough to make this consistent for
    // every consumer of 'record').
    $decodedparams = json_decode($conditionrecord->params);
    if (!is_object($decodedparams)) {
        $decodedparams = (object) $decodedparams;
    }

    $customdata = [
        'courseid' => $courseid,
        'ruleid' => $ruleid,
        'record' => $decodedparams,
    ];
    $conditioninstance->build_editform(
        new moodle_url($url, ['edit' => $editid]),
        $customdata,
        'post',
        '',
        ['class' => 'card p-4']
    );

    if ($conditioninstance->is_cancelled()) {
        redirect($url);
    } else if ($data = $conditioninstance->get_data()) {
        $conditioninstance->save_condition($data);
        redirect($url);
    }
} else if (!empty($type)) {
    $conditionrecord = (object) [
        'ruleid' => $ruleid,
        'conditiontype' => $type,
        'params' => json_encode([]),
    ];
    $conditioninstance = rule_component_loader::create_condition_instance($conditionrecord, $courseid);

    $customdata = [
        'courseid' => $courseid,
        'ruleid' => $ruleid,
    ];
    $conditioninstance->build_editform($url, $customdata, 'post', '', ['class' => 'card p-4']);

    if ($conditioninstance->is_cancelled()) {
        redirect($url);
    } else if ($data = $conditioninstance->get_data()) {
        $conditioninstance->save_condition($data);
        redirect($url);
    }
}

echo $OUTPUT->header();

$conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $ruleid]);

$conditionsfortemplate = [];
foreach ($conditions as $condition) {
    $listedconditioninstance = rule_component_loader::create_condition_instance($condition, $courseid);

    $header = $listedconditioninstance->get_header();
    $description = $listedconditioninstance->get_description();

    if (!empty($header) && !empty($description)) {
        $deleteurl = new moodle_url(
            '/local/coursedynamicrules/deletecondition.php',
            ['id' => $condition->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
        );
        $editurl = new moodle_url(
            '/local/coursedynamicrules/conditions.php',
            ['edit' => $condition->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
        );
        $conditionsfortemplate[] = [
            'id' => $condition->id,
            'header' => $header,
            'description' => $description,
            'deleteurl' => $deleteurl->out(false),
            'deletetitle' => get_string('deletecondition', 'local_coursedynamicrules'),
            'editurl' => $editurl->out(false),
            'edittitle' => get_string('editcondition', 'local_coursedynamicrules'),
        ];
    }
}

$conditionoptions = local_coursedynamicrules_load_condition_options();

// Render heading and branding using reusable renderable.
$headerrow = new \local_coursedynamicrules\output\header_with_brand('conditions');
echo $OUTPUT->render($headerrow);
echo html_writer::link($rulesurl, get_string('backtolistrules', 'local_coursedynamicrules'), ['class' => 'mb-3 d-block']);
echo html_writer::start_div('d-flex h-100');
echo $OUTPUT->render_from_template('local_coursedynamicrules/conditions_menu', ['options' => $conditionoptions]);
echo html_writer::start_div('col-8 h-100');
echo $OUTPUT->render_from_template('local_coursedynamicrules/conditions', ['conditions' => $conditionsfortemplate]);

if ($conditioninstance !== null) {
    $conditioninstance->show_editform();
}

echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();


/**
 * load the available item plugins from given subdirectory of $CFG->dirroot
 * the default is "mod/feedback/item"
 *
 * @param string $dir the subdir
 * @return array list of condition types
 */
function local_coursedynamicrules_load_conditions($dir = 'local/coursedynamicrules/classes/condition') {
    global $CFG;
    $conditiontypes = get_list_of_plugins($dir);
    $filtered = [];

    foreach ($conditiontypes as $conditiontype) {
        $conditionclass = "\\local_coursedynamicrules\\condition\\{$conditiontype}\\{$conditiontype}_condition";
        if (class_exists($conditionclass)) {
            $filtered[] = $conditiontype;
        }
    }
    return $filtered;
}

/**
 * load the available condtion options to use in conditions menu option
 *
 * @return array pluginnames as string
 */
function local_coursedynamicrules_load_condition_options() {
    global $CFG;
    $courseid = required_param('courseid', PARAM_INT);
    $ruleid = required_param('ruleid', PARAM_INT);
    $conditionoptions = [];

    if (!$conditiontypes = local_coursedynamicrules_load_conditions('local/coursedynamicrules/classes/condition')) {
        return [];
    }

    foreach ($conditiontypes as $conditiontype) {
        $url = new moodle_url(
            '/local/coursedynamicrules/conditions.php',
            ['courseid' => $courseid, 'type' => $conditiontype, 'ruleid' => $ruleid]
        );
        $conditionoptions[] = [
            'type' => $conditiontype,
            'visualname' => get_string($conditiontype, 'local_coursedynamicrules'),
            'action' => $url->out(false),
        ];
    }
    asort($conditionoptions);
    return $conditionoptions;
}
