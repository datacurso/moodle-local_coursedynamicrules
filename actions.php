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

// TODO Refactor this file.

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

echo $OUTPUT->header();

if (!\local_coursedynamicrules\helper\ownership::rule_belongs_to_course($ruleid, $courseid)) {
    throw new moodle_exception('invalidruleid', 'local_coursedynamicrules');
}

$actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $ruleid]);


$actionsfortemplate = [];
foreach ($actions as $action) {
    $actioninstance = rule_component_loader::create_action_instance($action, $courseid);

    $header = $actioninstance->get_header();
    $description = $actioninstance->get_description();

    $deleteurl = new moodle_url(
        '/local/coursedynamicrules/deleteaction.php',
        ['id' => $action->id, 'ruleid' => $ruleid, 'courseid' => $courseid]
    );

    if (!empty($header) && !empty($description)) {
        $actionsfortemplate[] = [
            'id' => $action->id,
            'header' => $actioninstance->get_header(),
            'description' => $actioninstance->get_description(),
            'deleteurl' => $deleteurl->out(false),
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
if (!empty($type)) {
    $actionrecord = (object) [
        'actiontype' => $type,
        'params' => json_encode([]),
    ];
    $actioninstance = rule_component_loader::create_action_instance($actionrecord);
    $customdata = [
        'courseid' => $courseid,
        'ruleid' => $ruleid,
    ];
    $actioninstance->build_editform($url, $customdata, 'post', '', ['class' => 'card p-4']);

    if ($actioninstance->is_cancelled()) {
        redirect($url);
    } else if ($data = $actioninstance->get_data()) {
        $actioninstance->save_action($data);
        redirect($url);
    } else {
        $actioninstance->show_editform();
    }
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
