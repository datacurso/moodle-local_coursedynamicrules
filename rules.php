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
 * TODO describe file rules
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\component_renderer;
use local_coursedynamicrules\helper\rule_component_loader;

require('../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

require_capability('local/coursedynamicrules:managerule', $context);

$url = new moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $courseid]);

$PAGE->set_title($course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

$rules = $DB->get_records('local_coursedynamicrules_rule', ['courseid' => $courseid]);

$table = new html_table();
$table->head[] = get_string('name', 'local_coursedynamicrules');
$table->head[] = get_string('conditions', 'local_coursedynamicrules');
$table->head[] = get_string('actions', 'local_coursedynamicrules');
$table->head[] = '';

foreach ($rules as $rule) {
    $conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $rule->id]);
    $actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $rule->id]);
    $conditionstext = '';
    $actionstext = '';

    $conditionsurl = new moodle_url(
        '/local/coursedynamicrules/conditions.php',
        ['courseid' => $courseid, 'ruleid' => $rule->id]
    );
    $actionsurl = new moodle_url(
        '/local/coursedynamicrules/actions.php',
        ['courseid' => $courseid, 'ruleid' => $rule->id]
    );


    if (empty($conditions)) {
        $conditionstext = html_writer::link(
            $conditionsurl,
            get_string('addconditions', 'local_coursedynamicrules')
        );
    } else {
        $conditioninstances = array_map(
            fn($condition) => rule_component_loader::create_condition_instance($condition, $courseid),
            $conditions
        );
        $conditionstext = component_renderer::descriptions_html($conditioninstances);
        $editlink = html_writer::link(
            $conditionsurl,
            $OUTPUT->pix_icon('t/edit', get_string('editconditions', 'local_coursedynamicrules'))
        );
        $conditionstext = html_writer::div($conditionstext);
        $conditionstext = html_writer::div($conditionstext . $editlink, 'd-flex', ['style' => 'gap: .8rem']);
    }
    if (empty($actions)) {
        $actionstext = html_writer::link(
            $actionsurl,
            get_string('addactions', 'local_coursedynamicrules')
        );
    } else {
        $actioninstances = array_map(
            fn($action) => rule_component_loader::create_action_instance($action, $courseid),
            $actions
        );
        $actionstext = component_renderer::descriptions_html($actioninstances);
        $editlink = html_writer::link(
            $actionsurl,
            $OUTPUT->pix_icon('t/edit', get_string('editactions', 'local_coursedynamicrules'))
        );
        $actionstext = html_writer::div($actionstext);
        $actionstext = html_writer::div($actionstext . $editlink, 'd-flex', ['style' => 'gap: .8rem']);
    }
    $editruleurl = new moodle_url('/local/coursedynamicrules/editrule.php', ['id' => $rule->id, 'courseid' => $courseid]);
    $deleteruleurl = new moodle_url('/local/coursedynamicrules/deleterule.php', ['id' => $rule->id, 'courseid' => $courseid]);
    $editrulelink = html_writer::link(
        $editruleurl,
        $OUTPUT->pix_icon('t/edit', get_string('editrule', 'local_coursedynamicrules'))
    );
    $deleterulelink = html_writer::link(
        $deleteruleurl,
        $OUTPUT->pix_icon('t/delete', get_string('deleterule', 'local_coursedynamicrules'))
    );

    $ruletext = html_writer::div($editrulelink . $deleterulelink, 'd-flex', ['style' => 'gap: .4rem']);

    if (!$rule->active) {
        $rule->name .= ' ' . html_writer::span(
            get_string('ruleinactive', 'local_coursedynamicrules'),
            'badge badge-secondary'
        );
    } else {
        $rule->name .= ' ' . html_writer::span(
            get_string('ruleactive', 'local_coursedynamicrules'),
            'badge badge-success'
        );
    }
    $table->data[] = [
        new html_table_cell($rule->name),
        new html_table_cell($conditionstext),
        new html_table_cell($actionstext),
        new html_table_cell($ruletext),
    ];
}

$editruleurl = new moodle_url('/local/coursedynamicrules/editrule.php', ['courseid' => $courseid]);
$addrulebutton = new single_button(
    $editruleurl,
    get_string('ruleadd', 'local_coursedynamicrules'),
    'get',
    true
);

// Render heading and branding on the same row.
$headerrow = new \local_coursedynamicrules\output\header_with_brand('rules');
echo $OUTPUT->render($headerrow);
echo html_writer::div($OUTPUT->render($addrulebutton), 'my-3');
echo html_writer::table($table);
echo $OUTPUT->footer();
