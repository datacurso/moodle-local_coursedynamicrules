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
use local_coursedynamicrules\helper\availability_user_status;
use local_coursedynamicrules\helper\page_gate;
use local_coursedynamicrules\helper\component_renderer;
use local_coursedynamicrules\helper\rule_component_loader;
use local_coursedynamicrules\helper\rule_lock;

require('../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

// The listing pair, decided in page_gate - the one door. A page script cannot be loaded from a
// unit test, so the decision lives where real roles can be thrown at it (page_gate_test.php), and
// the wiring test there pins that this page still makes the call.
page_gate::require_listing('rule', $context);

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

// The column links must obey the pair their target pages enforce (page_gate::require_listing):
// offering conditions.php/actions.php to a role that holds the rule pair but not the component
// pair is a live link into a guaranteed permission error (final-review finding). Hoisted:
// capabilities are context-constant for the whole listing.
$canlistconditions = has_capability('local/coursedynamicrules:viewcondition', $context)
    && has_capability('local/coursedynamicrules:managecondition', $context);
$canlistactions = has_capability('local/coursedynamicrules:viewaction', $context)
    && has_capability('local/coursedynamicrules:manageaction', $context);

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
        // Never offer what would be refused: adding needs createcondition AND an unsealed rule -
        // the upgrade seals every active rule, component-less ones included, and a live link into
        // a page whose add menu is hidden is a dead end. The fact (no conditions yet) stays,
        // muted, read off the fetched row so the listing pays no lock query per rule.
        $conditionstext = $canlistconditions
                && has_capability('local/coursedynamicrules:createcondition', $context)
                && !rule_lock::is_locked_row($rule)
            ? html_writer::link($conditionsurl, get_string('addconditions', 'local_coursedynamicrules'))
            : html_writer::span(get_string('addconditions', 'local_coursedynamicrules'), 'text-muted');
    } else {
        $conditioninstances = array_map(
            fn($condition) => rule_component_loader::create_condition_instance($condition, $courseid),
            $conditions
        );
        $conditionstext = component_renderer::descriptions_html($conditioninstances);
        // A sealed rule's components can be seen but never changed, and the affordance must not
        // promise otherwise: the eye replaces the pencil, the navigation stays. And no link at
        // all for a role the target page would refuse (the view*+manage* pair).
        $editlink = !$canlistconditions ? '' : html_writer::link(
            $conditionsurl,
            !rule_lock::is_locked_row($rule)
                ? $OUTPUT->pix_icon('t/edit', get_string('editconditions', 'local_coursedynamicrules'))
                : $OUTPUT->pix_icon('i/preview', get_string('viewconditions', 'local_coursedynamicrules'))
        );
        $conditionstext = html_writer::div($conditionstext);
        $conditionstext = html_writer::div($conditionstext . $editlink, 'd-flex', ['style' => 'gap: .8rem']);
    }
    if (empty($actions)) {
        // Same gate as the conditions column: capability AND unsealed.
        $actionstext = $canlistactions
                && has_capability('local/coursedynamicrules:createaction', $context)
                && !rule_lock::is_locked_row($rule)
            ? html_writer::link($actionsurl, get_string('addactions', 'local_coursedynamicrules'))
            : html_writer::span(get_string('addactions', 'local_coursedynamicrules'), 'text-muted');
    } else {
        $actioninstances = array_map(
            fn($action) => rule_component_loader::create_action_instance($action, $courseid),
            $actions
        );
        $actionstext = component_renderer::descriptions_html($actioninstances);
        // Same gate as the conditions column: the eye replaces the pencil on sealed rules,
        // and no link at all for a role the target page would refuse.
        $editlink = !$canlistactions ? '' : html_writer::link(
            $actionsurl,
            !rule_lock::is_locked_row($rule)
                ? $OUTPUT->pix_icon('t/edit', get_string('editactions', 'local_coursedynamicrules'))
                : $OUTPUT->pix_icon('i/preview', get_string('viewactions', 'local_coursedynamicrules'))
        );
        $actionstext = html_writer::div($actionstext);
        $actionstext = html_writer::div($actionstext . $editlink, 'd-flex', ['style' => 'gap: .8rem']);
    }
    $editruleurl = new moodle_url('/local/coursedynamicrules/editrule.php', ['id' => $rule->id, 'courseid' => $courseid]);
    $deleteruleurl = new moodle_url('/local/coursedynamicrules/deleterule.php', ['id' => $rule->id, 'courseid' => $courseid]);
    $editrulelink = '';
    if (has_capability('local/coursedynamicrules:updaterule', $context)) {
        $editrulelink = html_writer::link(
            $editruleurl,
            $OUTPUT->pix_icon('t/edit', get_string('editrule', 'local_coursedynamicrules'))
        );
    }
    $deleterulelink = '';
    if (has_capability('local/coursedynamicrules:deleterule', $context)) {
        $deleterulelink = html_writer::link(
            $deleteruleurl,
            $OUTPUT->pix_icon('t/delete', get_string('deleterule', 'local_coursedynamicrules'))
        );
    }

    $ruletext = html_writer::div($editrulelink . $deleterulelink, 'd-flex', ['style' => 'gap: .4rem']);

    // The name is user text and it reaches this table from two places: the form, which types it
    // PARAM_TEXT, and course restore, which writes it with no cleaning at all
    // (restore_local_coursedynamicrules_plugin.class.php). It is emitted raw into the cell below -
    // html_writer::table() does not escape cell text - so the escaping has to happen here.
    //
    // format_string() rather than s(), because PARAM_TEXT deliberately preserves valid multilang
    // markup (lib/classes/param.php:891 returns before the final strip_tags): s() would print that
    // markup on screen, while format_string() runs the filter that resolves it.
    //
    // What it does with what survives depends on a site setting, so only the guarantee that holds
    // in both branches is claimed here: the output can never carry executable HTML. With
    // $CFG->formatstringstriptags on - the default - tags are stripped and the remaining '<', '>'
    // and orphaned '&' are escaped (lib/classes/formatting.php:121). With it off, the string goes
    // through clean_text() instead, which keeps safe HTML and removes the rest
    // (formatting.php:131), so a name written as '<b>x</b>' renders bold on such a site rather
    // than showing its markup. Neither branch lets a script through.
    //
    // Unconditionally, for every rule. The first version of this block escaped only inside the if
    // below, so the same name rendered one way with a description and another way without - a
    // multilang name showed its raw markup in one row and resolved in the next.
    $rulename = component_renderer::escaped_name($rule->name, $context);

    // Hovering the name reveals the rule's description without spending a column on it, and only
    // when a description exists, so the rest render no empty tooltip box. Note what this does NOT
    // give: a native title attribute is unreachable by keyboard and by touch, because the span is
    // not focusable and carries no data-toggle. Anyone not using a mouse still has to open the
    // edit form to read a description. html_writer escapes the attribute value itself
    // (lib/classes/output/html_writer.php:113), so the raw description is what belongs here.
    if (trim((string) $rule->description) !== '') {
        $rulename = html_writer::span($rulename, '', ['title' => $rule->description]);
    }

    $rule->name = $rulename;

    // One badge, four states (product directives 2026-08-31/09-01), read off the row already
    // fetched. Active = running, whether or not it has fired: event-driven rules stay active and
    // fire repeatedly, so "executed" on a live rule would be noise. Executed = stopped AND the
    // engine fired it at least once - which is exactly where one-shot cron rules land, because
    // no_complete_activity_task deactivates the rule right after executing it; no trigger-type
    // sniffing needed, the state pair encodes it. Paused = activated once, stopped, never fired.
    // Inactive = never activated (the only editable state).
    if ($rule->active) {
        $rule->name .= ' ' . html_writer::span(
            get_string('ruleactive', 'local_coursedynamicrules'),
            'badge badge-success'
        );
    } else if (rule_lock::is_locked_row($rule) && !empty($rule->lastexecutiontime)) {
        $rule->name .= ' ' . html_writer::span(
            get_string('ruleexecuted', 'local_coursedynamicrules'),
            'badge local_coursedynamicrules_badge_executed'
        );
    } else if (rule_lock::is_locked_row($rule)) {
        $rule->name .= ' ' . html_writer::span(
            get_string('rulepaused', 'local_coursedynamicrules'),
            'badge badge-warning'
        );
    } else {
        $rule->name .= ' ' . html_writer::span(
            get_string('ruleinactive', 'local_coursedynamicrules'),
            'badge badge-secondary'
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
    single_button::BUTTON_PRIMARY
);

// Render heading and branding on the same row.
$headerrow = new \local_coursedynamicrules\output\header_with_brand('rules');
echo $OUTPUT->render($headerrow);

// Losing the per-user availability restriction silently un-hides every activity the rules gate,
// so the operator has to be told here rather than discovering it through exposed content.
if (!availability_user_status::is_enabled()) {
    echo $OUTPUT->notification(
        get_string('availabilityuserdisabledwarning', 'local_coursedynamicrules'),
        \core\output\notification::NOTIFY_WARNING
    );
}

if (has_capability('local/coursedynamicrules:createrule', $context)) {
    echo html_writer::div($OUTPUT->render($addrulebutton), 'my-3');
}
echo html_writer::table($table);
echo $OUTPUT->footer();
