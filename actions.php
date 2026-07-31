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
 * TODO describe file actions
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\rule_component_loader;

require('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$ruleid = required_param('ruleid', PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/coursedynamicrules:manageaction', $context);

$url = new moodle_url('/local/coursedynamicrules/actions.php', ['courseid' => $courseid, 'ruleid' => $ruleid]);
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
$actioninstance = null;
if ($editid > 0) {
    // Ownership is checked here (GET) and re-checked implicitly on POST: $editid comes from
    // optional_param() again on the resubmitted request, and the row is only writable through
    // save_action() -> upsert(), which never touches ruleid. get_action() enforces that the
    // component belongs to BOTH the requested course AND the requested rule, so a tampered hidden
    // id (foreign course, or a different rule in the same course) is rejected before any form
    // render or DB write.
    $actionrecord = \local_coursedynamicrules\helper\ownership::get_action($editid, $courseid, $ruleid);
    $actioninstance = rule_component_loader::create_action_instance($actionrecord, $courseid);

    // Json_decode() returns a PHP array (not stdClass) for a JSON array such as "[]", which
    // `empty()`-based edit-mode checks in the form base classes would treat as "no record"
    // (empty() is only ever false for objects, so a cast is enough to make this consistent for
    // every consumer of 'record').
    $decodedparams = json_decode($actionrecord->params);
    if (!is_object($decodedparams)) {
        $decodedparams = (object) $decodedparams;
    }

    $customdata = [
        'courseid' => $courseid,
        'ruleid' => $ruleid,
        'record' => $decodedparams,
    ];
    $actioninstance->build_editform(
        new moodle_url($url, ['edit' => $editid]),
        $customdata,
        'post',
        '',
        ['class' => 'card p-4']
    );

    if ($actioninstance->is_cancelled()) {
        redirect($url);
    } else if ($data = $actioninstance->get_data()) {
        $actioninstance->save_action($data);
        \local_coursedynamicrules\event\action_updated::create([
            'context' => $context,
            'objectid' => $editid,
        ])->trigger();
        redirect($url);
    }
} else if (!empty($type)) {
    $actionrecord = (object) [
        'ruleid' => $ruleid,
        'actiontype' => $type,
        'params' => json_encode([]),
    ];
    $actioninstance = rule_component_loader::create_action_instance($actionrecord, $courseid);
    $customdata = [
        'courseid' => $courseid,
        'ruleid' => $ruleid,
    ];
    $actioninstance->build_editform($url, $customdata, 'post', '', ['class' => 'card p-4']);

    if ($actioninstance->is_cancelled()) {
        redirect($url);
    } else if ($data = $actioninstance->get_data()) {
        $actionid = $actioninstance->save_action($data);
        \local_coursedynamicrules\event\action_created::create([
            'context' => $context,
            'objectid' => $actionid,
        ])->trigger();
        redirect($url);
    }
}

echo $OUTPUT->header();

$actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $ruleid]);


$actionsfortemplate = [];
foreach ($actions as $action) {
    $listedactioninstance = rule_component_loader::create_action_instance($action, $courseid);

    $header = $listedactioninstance->get_header();
    $description = $listedactioninstance->get_description();

    $deleteurl = new moodle_url(
        '/local/coursedynamicrules/deleteaction.php',
        ['id' => $action->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
    );
    $editurl = new moodle_url(
        '/local/coursedynamicrules/actions.php',
        ['edit' => $action->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
    );

    if (!empty($header) && !empty($description)) {
        $actionsfortemplate[] = [
            'id' => $action->id,
            'header' => $header,
            'description' => $description,
            'deleteurl' => $deleteurl->out(false),
            'deletetitle' => get_string('deleteaction', 'local_coursedynamicrules'),
            'editurl' => $editurl->out(false),
            'edittitle' => get_string('editaction', 'local_coursedynamicrules'),
        ];
    }
}

$actionoptions = local_coursedynamicrules_load_action_options();


// Render heading and branding using reusable renderable.
$headerrow = new \local_coursedynamicrules\output\header_with_brand('actions');
echo $OUTPUT->render($headerrow);
echo html_writer::link($rulesurl, get_string('backtolistrules', 'local_coursedynamicrules'), ['class' => 'mb-3 d-block']);
echo html_writer::start_div('d-flex');
echo $OUTPUT->render_from_template('local_coursedynamicrules/conditions_menu', ['options' => $actionoptions]);
echo html_writer::start_div('col-8');
echo $OUTPUT->render_from_template('local_coursedynamicrules/conditions', ['conditions' => $actionsfortemplate]);

if ($actioninstance !== null) {
    $actioninstance->show_editform();
}

echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();


/**
 * load the available item plugins from given subdirectory of $CFG->dirroot
 * the default is "mod/feedback/item"
 *
 * @param string $dir the subdir
 * @return array list of action types
 */
function local_coursedynamicrules_load_actions($dir = 'local/coursedynamicrules/classes/action') {
    global $CFG;
    $actiontypes = get_list_of_plugins($dir);
    $filtered = [];

    foreach ($actiontypes as $actiontype) {
        $conditionclass = "\\local_coursedynamicrules\\action\\{$actiontype}\\{$actiontype}_action";

        if (class_exists($conditionclass)) {
            $filtered[] = $actiontype;
        }
    }
    return $filtered;
}

/**
 * load the available condtion options to use in actions menu option
 *
 * @return array pluginnames as string
 */
function local_coursedynamicrules_load_action_options() {
    global $CFG;
    $courseid = required_param('courseid', PARAM_INT);
    $ruleid = required_param('ruleid', PARAM_INT);
    $actionoptions = [];

    if (!$actiontypes = local_coursedynamicrules_load_actions('local/coursedynamicrules/classes/action')) {
        return [];
    }

    foreach ($actiontypes as $actiontype) {
        $url = new moodle_url(
            '/local/coursedynamicrules/actions.php',
            ['courseid' => $courseid, 'type' => $actiontype, 'ruleid' => $ruleid]
        );
        $actionoptions[] = [
            'type' => $actiontype,
            'visualname' => get_string($actiontype, 'local_coursedynamicrules'),
            'action' => $url->out(false),
        ];
    }
    asort($actionoptions);
    return $actionoptions;
}
