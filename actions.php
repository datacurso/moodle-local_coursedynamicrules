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
use local_coursedynamicrules\helper\availability_user_status;
use local_coursedynamicrules\helper\page_gate;
use local_coursedynamicrules\helper\rule_lock;
use local_coursedynamicrules\helper\rule_component_loader;

require('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$ruleid = required_param('ruleid', PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
// The listing pair, decided in page_gate - the one door. A page script cannot be loaded from a
// unit test, so the decision lives where real roles can be thrown at it (page_gate_test.php), and
// the wiring test there pins that this page still makes the call.
page_gate::require_listing('action', $context);

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

// One lock query per request, not per row: the fact is rule-level and constant here
// (round-3 confirmed suggestion - rules.php pays zero per-row queries for the same fact).
$rulelocked = rule_lock::is_locked($ruleid);

// Build and process the edit/create form BEFORE any output is echoed: a cancelled or submitted
// form redirects, and redirect() cannot run after $OUTPUT->header() has already been sent.
$editid = optional_param('edit', 0, PARAM_INT);
$actioninstance = null;
$editingexisting = false;
$formurl = $url;
if ($editid > 0) {
    // Bounded in-place editing (product directive 2026-08-31): a component can be edited only
    // while its rule was never activated - the seal is exactly the advisory boundary the 1.8.1
    // withholding was waiting for. This branch must run before the create branch below, because
    // an edit submission also carries a 'type' and would otherwise create a brand new action.
    require_capability('local/coursedynamicrules:updateaction', $context);
    rule_lock::require_unlocked($ruleid);
    $actionrecord = \local_coursedynamicrules\helper\ownership::get_action($editid, $courseid, $ruleid);
    $actioninstance = rule_component_loader::create_action_instance($actionrecord, $courseid);
    $editingexisting = true;
    // The form must post back into THIS branch, or the hidden 'type' would create a duplicate.
    $formurl = new moodle_url($url, ['edit' => $editid]);
} else if (!empty($type)) {
    // The add menu is only rendered for a role that holds this, but the type is a URL
    // parameter: refuse it here as well.
    page_gate::require_creation('action', $context);
    // A locked rule accepts no new components - the menu below is hidden too, but a URL is
    // not a menu.
    rule_lock::require_unlocked($ruleid);
    $actionrecord = (object) [
        'ruleid' => $ruleid,
        'actiontype' => $type,
        'params' => json_encode([]),
    ];
    $actioninstance = rule_component_loader::create_action_instance($actionrecord, $courseid);
}

if ($actioninstance !== null) {
    $customdata = [
        'courseid' => $courseid,
        'ruleid' => $ruleid,
    ];
    if ($editingexisting) {
        // The stored params preload the form (action_form reads customdata['record']).
        $customdata['record'] = json_decode((string) $actionrecord->params);
    }
    $actioninstance->build_editform($formurl, $customdata, 'post', '', ['class' => 'card p-4']);

    if ($actioninstance->is_cancelled()) {
        redirect($url);
    } else if ($data = $actioninstance->get_data()) {
        $actionid = $actioninstance->save_action($data);
        $eventclass = $editingexisting
            ? \local_coursedynamicrules\event\action_updated::class
            : \local_coursedynamicrules\event\action_created::class;
        $eventclass::create([
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

    if (!empty($header) && !empty($description)) {
        $row = [
            'id' => $action->id,
            'header' => $header,
            'description' => $description,
        ];

        // Bounded editing: the pencil appears only while the rule was never activated and the
        // role holds updateaction - the same pair of gates the edit endpoint enforces.
        if (has_capability('local/coursedynamicrules:updateaction', $context) && !$rulelocked) {
            $editurl = new moodle_url(
                '/local/coursedynamicrules/actions.php',
                ['edit' => $action->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
            );
            $row['editurl'] = $editurl->out(false);
            $row['edittitle'] = get_string('editaction', 'local_coursedynamicrules');
        }

        // The trash can needs deleteaction - held by managers AND, since 1.9.0, the editing
        // teacher archetype (RISK_DATALOSS, explicit PROHIBITs respected) - and offering it to a
        // role without the capability puts a control in front of them that the endpoint then
        // refuses with an error page: never offer what would be refused. The endpoint keeps
        // its own check either way; this only aligns the offer with it.
        if (has_capability('local/coursedynamicrules:deleteaction', $context) && !$rulelocked) {
            $deleteurl = new moodle_url(
                '/local/coursedynamicrules/deleteaction.php',
                ['id' => $action->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
            );
            $row['deleteurl'] = $deleteurl->out(false);
            $row['deletetitle'] = get_string('deleteaction', 'local_coursedynamicrules');
        }

        $actionsfortemplate[] = $row;
    }
}

$actionoptions = local_coursedynamicrules_load_action_options();


// Render heading and branding using reusable renderable.
$headerrow = new \local_coursedynamicrules\output\header_with_brand('actions');
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

echo html_writer::start_div('d-flex');

if (has_capability('local/coursedynamicrules:createaction', $context) && !$rulelocked) {
    echo $OUTPUT->render_from_template('local_coursedynamicrules/conditions_menu', ['options' => $actionoptions]);
}
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
