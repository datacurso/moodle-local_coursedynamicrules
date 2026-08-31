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
 * TODO describe file editrule
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$ruleid = optional_param('id', 0, PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

// A first, fast refusal based on the URL id - which form gets RENDERED. It is not the
// authoritative check: the id that gets WRITTEN is the form's hidden field, decided separately in
// ownership::resolve_writable_ruleid() below, because the two ids are different fields and only
// the second one matters for the write.
if ($ruleid) {
    require_capability('local/coursedynamicrules:updaterule', $context);
} else {
    require_capability('local/coursedynamicrules:createrule', $context);
}

$url = new moodle_url('/local/coursedynamicrules/editrule.php', ['courseid' => $courseid, 'id' => $ruleid]);

// Where to land after saving or cancelling. The listing needs viewrule AND managerule; a role that
// may only create reaches this page by URL, saves successfully, and would then be redirected into a
// permission error AFTER the write - work done, error shown. Such a role lands on the course page
// instead, with the same success message.
$canseelisting = has_capability('local/coursedynamicrules:viewrule', $context)
    && has_capability('local/coursedynamicrules:managerule', $context);
$rulesurl = $canseelisting
    ? new moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $courseid])
    : new moodle_url('/course/view.php', ['id' => $courseid]);

$PAGE->set_title($course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// The activation confirmation's Continue lands here, BEFORE any output: activating is the one
// moment the rule locks forever, so it happens only through this sesskey-protected step - the
// save path below deliberately holds 'active' back and sends the user here instead.
if ($ruleid && optional_param('doactivate', 0, PARAM_INT)) {
    require_sesskey();
    \local_coursedynamicrules\helper\ownership::get_rule($ruleid, $courseid);

    // Re-checked server-side: the form validated completeness, but this URL is reachable on its
    // own, and an incomplete locked rule can never fire and never be finished.
    if (
        !\local_coursedynamicrules\helper\rule_lock::is_locked($ruleid)
            && \local_coursedynamicrules\helper\rule_lock::is_complete($ruleid)
    ) {
        $DB->set_field('local_coursedynamicrules_rule', 'active', 1, ['id' => $ruleid]);
        $DB->set_field('local_coursedynamicrules_rule', 'timemodified', time(), ['id' => $ruleid]);
        \local_coursedynamicrules\helper\rule_lock::stamp_if_active($ruleid);
        \local_coursedynamicrules\event\rule_updated::create([
            'context' => $context,
            'objectid' => $ruleid,
        ])->trigger();
        redirect(
            $rulesurl,
            get_string('ruleactivatedsuccessfully', 'local_coursedynamicrules'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    redirect(
        $rulesurl,
        get_string('ruleactivationincomplete', 'local_coursedynamicrules'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$rule = new stdClass();
if ($ruleid) {
    $pagetitle = get_string('editrule', 'local_coursedynamicrules');
    // Ensure the rule belongs to this course before loading it (prevents cross-course access).
    $rule = \local_coursedynamicrules\helper\ownership::get_rule($ruleid, $courseid);
} else {
    $pagetitle = get_string('createrule', 'local_coursedynamicrules');
}

// The confirmation between saving and activating. Cancel keeps everything saved and inactive;
// Continue goes through the sesskey-protected doactivate branch above. Rendered before the form so
// the page shows one question, not a form beside a question.
if (
    $ruleid && optional_param('confirmactivate', 0, PARAM_INT)
        && !\local_coursedynamicrules\helper\rule_lock::is_locked($ruleid)
        && \local_coursedynamicrules\helper\rule_lock::is_complete($ruleid)
) {
    echo $OUTPUT->header();
    $continueurl = new moodle_url('/local/coursedynamicrules/editrule.php', [
        'courseid' => $courseid, 'id' => $ruleid, 'doactivate' => 1, 'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->confirm(
        get_string('ruleactivateconfirm', 'local_coursedynamicrules'),
        new single_button(
            $continueurl,
            get_string('ruleactivateconfirmbutton', 'local_coursedynamicrules'),
            'post',
            single_button::BUTTON_PRIMARY
        ),
        $rulesurl
    );
    echo $OUTPUT->footer();
    exit;
}

// The form is built and processed BEFORE any output: every branch below redirects, and
// redirect() after the header is the exact structural debt the acceptance suite surfaced the
// first time anything created a rule through this page (every earlier scenario used the
// generator). Same bug class, same fix, as history.php.
$ruleform = new local_coursedynamicrules\form\rule_form($url, ['rule' => $rule, 'courseid' => $courseid]);

if ($ruleform->is_cancelled()) {
    redirect($rulesurl);
} else if ($data = $ruleform->get_data()) {
    $data->timemodified = time();
    $data->active = $data->active ?? 0;
    // Never trust the submitted course id: the rule always belongs to the current course.
    $data->courseid = $courseid;
    // Never trust the submitted rule id either: a tampered hidden id must not update another
    // course's rule. Re-validate the write target against the course (throws if foreign).
    $data->id = \local_coursedynamicrules\helper\ownership::resolve_writable_ruleid($data->id ?? 0, $courseid, $context);

    // A locked rule accepts exactly one change - the active toggle. The frozen form is the polite
    // face; THIS is the enforcement: a tab opened before the rule locked still submits a full
    // payload, and the server re-decides at write time.
    $waslocked = !empty($data->id) && \local_coursedynamicrules\helper\rule_lock::is_locked((int) $data->id);
    if ($waslocked) {
        $data = \local_coursedynamicrules\helper\rule_lock::sanitise_locked_write($data);
    }

    // First activation never happens inside a plain save. Activating is the moment the rule locks
    // forever, so the save persists every edit with 'active' held at its stored value, and the
    // user is sent to a confirmation that owns the actual activation. The form already validated
    // completeness; the confirm endpoint re-checks it anyway.
    $confirmactivation = !$waslocked && !empty($data->active);
    if ($confirmactivation) {
        global $DB;
        $data->active = empty($data->id)
            ? 0
            : (int) $DB->get_field('local_coursedynamicrules_rule', 'active', ['id' => $data->id]);
    }

    if (empty($data->id)) {
        $data->timecreated = time();
        $newruleid = $DB->insert_record('local_coursedynamicrules_rule', $data);
        \local_coursedynamicrules\event\rule_created::create([
            'context' => $context,
            'objectid' => $newruleid,
        ])->trigger();
        redirect(
            $rulesurl,
            get_string('ruleaddedsuccessfully', 'local_coursedynamicrules'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $DB->update_record('local_coursedynamicrules_rule', $data);
        \local_coursedynamicrules\event\rule_updated::create([
            'context' => $context,
            'objectid' => $data->id,
        ])->trigger();
        \local_coursedynamicrules\helper\rule_lock::stamp_if_active((int) $data->id);
        if ($confirmactivation) {
            redirect(new moodle_url('/local/coursedynamicrules/editrule.php', [
                'courseid' => $courseid, 'id' => $data->id, 'confirmactivate' => 1,
            ]));
        }
        redirect(
            $rulesurl,
            get_string('ruleupdatedsuccessfully', 'local_coursedynamicrules'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

echo $OUTPUT->header();

$headingkey = $ruleid ? 'editrule' : 'createrule';
$headerrow = new \local_coursedynamicrules\output\header_with_brand($headingkey, 'local_coursedynamicrules', false);
echo $OUTPUT->render($headerrow);

$ruleform->display();
echo $OUTPUT->footer();
