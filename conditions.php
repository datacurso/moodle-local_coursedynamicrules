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

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\availability_user_status;
use local_coursedynamicrules\helper\page_gate;
use local_coursedynamicrules\helper\rule_lock;
use local_coursedynamicrules\helper\rule_component_loader;

require('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$ruleid = required_param('ruleid', PARAM_INT);
$type = optional_param('type', '', PARAM_TEXT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
// The listing pair, decided in page_gate - the one door. A page script cannot be loaded from a
// unit test, so the decision lives where real roles can be thrown at it (page_gate_test.php), and
// the wiring test there pins that this page still makes the call.
page_gate::require_listing('condition', $context);

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

// One lock query per request, not per row: the fact is rule-level and constant here
// (round-3 confirmed suggestion - rules.php pays zero per-row queries for the same fact).
$rulelocked = rule_lock::is_locked($ruleid);

// Build and process the edit/create form BEFORE any output is echoed: a cancelled or submitted
// form redirects, and redirect() cannot run after $OUTPUT->header() has already been sent.
$editid = optional_param('edit', 0, PARAM_INT);
if ($editid > 0) {
    // Editing an existing condition in place is not available in this release. Bounce a bookmarked
    // link, or a form left open before the upgrade, back to the listing. This must happen before
    // the create branch below, because an edit submission also carries a 'type' and would
    // otherwise be read as a request to create a brand new condition.
    redirect($url, get_string('editingunavailable', 'local_coursedynamicrules'));
}
$conditioninstance = null;
if (!empty($type)) {
    // The add menu is only rendered for a role that holds this, but the type is a URL
    // parameter: refuse it here as well.
    page_gate::require_creation('condition', $context);
    // A locked rule accepts no new components - the menu below is hidden too, but a URL is
    // not a menu.
    rule_lock::require_unlocked($ruleid);
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
        $conditionid = $conditioninstance->save_condition($data);
        \local_coursedynamicrules\event\condition_created::create([
            'context' => $context,
            'objectid' => $conditionid,
        ])->trigger();
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
        $row = [
            'id' => $condition->id,
            'header' => $header,
            'description' => $description,
        ];

        // The trash can needs deletecondition - manager-only by archetype, with RISK_DATALOSS - and
        // the shared template renders it whenever 'deleteurl' is present. Offering it to every row
        // put a control in front of the editing teacher that the endpoint then refused with an
        // error page: never offer what would be refused. The endpoint keeps its own check either
        // way; this only aligns the offer with it.
        if (has_capability('local/coursedynamicrules:deletecondition', $context) && !$rulelocked) {
            $deleteurl = new moodle_url(
                '/local/coursedynamicrules/deletecondition.php',
                ['id' => $condition->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
            );
            $row['deleteurl'] = $deleteurl->out(false);
            $row['deletetitle'] = get_string('deletecondition', 'local_coursedynamicrules');
        }

        $conditionsfortemplate[] = $row;
    }
}

$conditionoptions = local_coursedynamicrules_load_condition_options();

// Render heading and branding using reusable renderable.
$headerrow = new \local_coursedynamicrules\output\header_with_brand('conditions');
echo $OUTPUT->render($headerrow);
echo html_writer::link($rulesurl, get_string('backtolistrules', 'local_coursedynamicrules'), ['class' => 'mb-3 d-block']);
// Losing the per-user availability restriction silently un-hides every activity the rules
// gate, so the operator has to be told here rather than discovering it through exposed
// content.
if (!availability_user_status::is_enabled()) {
    echo $OUTPUT->notification(
        get_string('availabilityuserdisabledwarning', 'local_coursedynamicrules'),
        \core\output\notification::NOTIFY_WARNING
    );
}

echo html_writer::start_div('d-flex h-100');

if (has_capability('local/coursedynamicrules:createcondition', $context) && !$rulelocked) {
    echo $OUTPUT->render_from_template('local_coursedynamicrules/conditions_menu', ['options' => $conditionoptions]);
}
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
