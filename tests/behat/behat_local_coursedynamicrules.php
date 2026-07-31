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
 * Behat steps for local_coursedynamicrules plugin.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Gherkin\Node\PyStringNode;

/**
 * Behat steps for local_coursedynamicrules plugin.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_coursedynamicrules extends behat_base {
    /**
     * Create no course access rules with sendnotification action.
     *
     * @Given /^the following local coursedynamicrules no course access rules exist:$/
     * @param TableNode $table Table data.
     */
    public function the_following_local_coursedynamicrules_no_course_access_rules_exist(TableNode $table): void {
        global $DB;

        foreach ($table->getHash() as $row) {
            $course = $DB->get_record('course', ['shortname' => $row['course']], '*', MUST_EXIST);
            $ruleid = (int)$DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id,
                'name' => 'Behat no course access rule',
                'description' => 'Behat generated rule',
                'active' => 1,
                'lastexecutiontime' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);

            $DB->insert_record('local_coursedynamicrules_condition', (object) [
                'ruleid' => $ruleid,
                'conditiontype' => 'no_course_access',
                'params' => json_encode([
                    'periodvalue' => (int)$row['periodvalue'],
                    'periodunit' => trim($row['periodunit']),
                    'nexttimeperiod' => 0,
                ]),
                'lastexecutiontime' => null,
            ]);

            $primaryroles = $this->resolve_role_shortnames_to_ids($row['primaryroles']);
            $copyroles = $this->resolve_role_shortnames_to_ids($row['copyroles'] ?? '');

            $DB->insert_record('local_coursedynamicrules_action', (object) [
                'ruleid' => $ruleid,
                'actiontype' => 'sendnotification',
                'params' => json_encode([
                    'messagesubject' => trim($row['subject']),
                    'messagebody' => trim($row['body']),
                    'primaryroleids' => $primaryroles,
                    'copyroleids' => $copyroles,
                ]),
                'lastexecutiontime' => null,
            ]);
        }
    }

    /**
     * Create rules with a create AI activity action carrying the given prompt.
     *
     * @Given /^the following local coursedynamicrules AI activity actions exist:$/
     * @param TableNode $table Table data with columns: course, prompt.
     */
    public function the_following_local_coursedynamicrules_ai_activity_actions_exist(TableNode $table): void {
        global $DB;

        foreach ($table->getHash() as $row) {
            $course = $DB->get_record('course', ['shortname' => $row['course']], '*', MUST_EXIST);
            $ruleid = (int)$DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id,
                'name' => 'Behat AI activity rule',
                'description' => 'Behat generated rule',
                'active' => 1,
                'lastexecutiontime' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);

            $DB->insert_record('local_coursedynamicrules_action', (object) [
                'ruleid' => $ruleid,
                'actiontype' => 'createaiactivity',
                'params' => json_encode([
                    'message' => html_entity_decode($row['prompt'], ENT_QUOTES | ENT_HTML5),
                    'generateimages' => false,
                    'sectionnum' => 0,
                    'beforemod' => null,
                ]),
                'lastexecutiontime' => null,
            ]);
        }
    }

    /**
     * Visit the delete confirmation page for the most recent action in a course.
     *
     * @When /^I visit the coursedynamicrules delete page for the latest action in course "(?P<shortname>[^"]*)"$/
     * @param string $shortname Course shortname.
     */
    public function i_visit_the_coursedynamicrules_delete_page_for_the_latest_action_in_course(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $action = $DB->get_record_sql(
            "SELECT a.id, a.ruleid
               FROM {local_coursedynamicrules_action} a
               JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
              WHERE r.courseid = :courseid
           ORDER BY a.id DESC",
            ['courseid' => $course->id],
            IGNORE_MULTIPLE
        );

        $url = new moodle_url('/local/coursedynamicrules/deleteaction.php', [
            'id' => $action->id,
            'courseid' => $course->id,
            'ruleid' => $action->ruleid,
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Visit the in-place edit URL for the most recent condition in a course.
     *
     * Proves the editor cannot be reached by a direct link or a bookmark, not merely that the
     * listing stopped offering a control for it.
     *
     * @When /^I visit the coursedynamicrules edit page for the latest condition in course "(?P<shortname>[^"]*)"$/
     * @param string $shortname Course shortname.
     */
    public function i_visit_the_coursedynamicrules_edit_page_for_latest_condition(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $condition = $DB->get_record_sql(
            "SELECT c.id, c.ruleid
               FROM {local_coursedynamicrules_condition} c
               JOIN {local_coursedynamicrules_rule} r ON r.id = c.ruleid
              WHERE r.courseid = :courseid
           ORDER BY c.id DESC",
            ['courseid' => $course->id],
            IGNORE_MULTIPLE
        );

        $url = new moodle_url('/local/coursedynamicrules/conditions.php', [
            'edit' => $condition->id,
            'courseid' => $course->id,
            'ruleid' => $condition->ruleid,
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Set users last access timestamps per course.
     *
     * @Given /^the following users last accessed courses:$/
     * @param TableNode $table Table data.
     */
    public function the_following_users_last_accessed_courses(TableNode $table): void {
        global $DB;

        foreach ($table->getHash() as $row) {
            $user = $DB->get_record('user', ['username' => $row['username']], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['shortname' => $row['course']], '*', MUST_EXIST);
            $timeaccess = time() - (int)$row['secondsago'];

            $existing = $DB->get_record('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id], 'id');
            if ($existing) {
                $DB->set_field('user_lastaccess', 'timeaccess', $timeaccess, ['id' => $existing->id]);
            } else {
                $DB->insert_record('user_lastaccess', (object) [
                    'userid' => $user->id,
                    'courseid' => $course->id,
                    'timeaccess' => $timeaccess,
                ]);
            }
        }
    }

    /**
     * Delete local_coursedynamicrules notifications for users.
     *
     * @Given /^the following local coursedynamicrules notifications are deleted:$/
     * @param TableNode $table Table data.
     */
    public function the_following_local_coursedynamicrules_notifications_are_deleted(TableNode $table): void {
        global $DB;

        foreach ($table->getHash() as $row) {
            $user = $DB->get_record('user', ['username' => $row['username']], '*', MUST_EXIST);
            $DB->delete_records('notifications', ['useridto' => $user->id, 'component' => 'local_coursedynamicrules']);
        }
    }

    /**
     * Assert number of local_coursedynamicrules notifications for user.
     *
     * @Then /^"(?P<username>[^"]*)" should have (?P<count>\d+) local coursedynamicrules notifications$/
     * @param string $username Username.
     * @param int $count Expected count.
     */
    public function should_have_local_coursedynamicrules_notifications(string $username, int $count): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $actual = (int)$DB->count_records('notifications', [
            'useridto' => $user->id,
            'component' => 'local_coursedynamicrules',
        ]);

        if ($actual !== $count) {
            throw new Exception('Expected ' . $count . ' notifications for ' . $username . ', got ' . $actual . '.');
        }
    }

    /**
     * Assert latest local_coursedynamicrules notification field contains expected text.
     *
     * @Then /^the latest local coursedynamicrules notification for "([^"]*)" should contain "([^"]*)" in "([^"]*)"$/
     * @param string $username Username.
     * @param string $expected Expected substring.
     * @param string $field Notification field.
     */
    public function latest_local_coursedynamicrules_notification_should_contain(
        string $username,
        string $expected,
        string $field
    ): void {
        global $DB;

        $allowedfields = ['subject', 'fullmessage', 'fullmessagehtml', 'smallmessage'];
        if (!in_array($field, $allowedfields)) {
            throw new Exception('Field not allowed: ' . $field);
        }

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $records = $DB->get_records('notifications', [
            'useridto' => $user->id,
            'component' => 'local_coursedynamicrules',
        ], 'id DESC', 'id,' . $field, 0, 1);

        if (empty($records)) {
            throw new Exception('No local_coursedynamicrules notifications found for user ' . $username . '.');
        }

        $notification = reset($records);
        $value = (string)($notification->{$field} ?? '');

        if (mb_strpos($value, $expected) === false) {
            throw new Exception('Expected to find "' . $expected . '" in ' . $field . ', actual value: ' . $value);
        }
    }

    /**
     * Assert latest local_coursedynamicrules notification field matches expected text exactly.
     *
     * @Then /^the latest local coursedynamicrules notification for "(?P<username>[^"]*)" in "(?P<field>[^"]*)" should be exactly:$/
     * @param string $username Username.
     * @param string $field Notification field.
     * @param PyStringNode $expected Expected value.
     */
    public function latest_local_coursedynamicrules_notification_should_be_exactly(
        string $username,
        string $field,
        PyStringNode $expected
    ): void {
        global $DB;

        $allowedfields = ['subject', 'fullmessage', 'fullmessagehtml', 'smallmessage'];
        if (!in_array($field, $allowedfields)) {
            throw new Exception('Field not allowed: ' . $field);
        }

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $records = $DB->get_records('notifications', [
            'useridto' => $user->id,
            'component' => 'local_coursedynamicrules',
        ], 'id DESC', 'id,' . $field, 0, 1);

        if (empty($records)) {
            throw new Exception('No local_coursedynamicrules notifications found for user ' . $username . '.');
        }

        $notification = reset($records);
        $actual = (string)($notification->{$field} ?? '');
        $expectedvalue = $expected->getRaw();

        if ($actual !== $expectedvalue) {
            throw new Exception(
                'Expected exact value in ' . $field . ' for ' . $username . ' but got: ' . $actual
            );
        }
    }

    /**
     * Create rules with a grade_in_activity condition and a sendnotification action.
     *
     * Used by the grade_in_activity edit Behat scenario: builds a real rule/condition/action row
     * set against an existing graded activity so the dynamic sub-form has genuine grade item data
     * to preload.
     *
     * @Given /^the following local coursedynamicrules grade in activity rules exist:$/
     * @param TableNode $table Table data: course | activity (cm idnumber) | condition (gradegte|gradelt) | value | subject.
     */
    public function the_following_local_coursedynamicrules_grade_in_activity_rules_exist(TableNode $table): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        foreach ($table->getHash() as $row) {
            $course = $DB->get_record('course', ['shortname' => $row['course']], '*', MUST_EXIST);
            $cm = $DB->get_record('course_modules', ['idnumber' => $row['activity']], '*', MUST_EXIST);
            $modname = $DB->get_field('modules', 'name', ['id' => $cm->module], MUST_EXIST);

            $gradeitem = \grade_item::fetch([
                'iteminstance' => $cm->instance,
                'itemmodule' => $modname,
                'itemtype' => 'mod',
                'itemnumber' => 0,
            ]);
            if (!$gradeitem) {
                throw new Exception('No grade item found for activity "' . $row['activity'] . '".');
            }

            $ruleid = (int)$DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id,
                'name' => 'Behat grade in activity rule',
                'description' => 'Behat generated rule',
                'active' => 1,
                'lastexecutiontime' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);

            $condition = trim($row['condition']);
            $key = $condition . '_' . $gradeitem->id;
            $gradeitemsconditions = (object) [
                $key => (object) [
                    'gradeitem' => $gradeitem->id,
                    'condition' => $condition,
                    'value' => (float)$row['value'],
                ],
            ];

            $DB->insert_record('local_coursedynamicrules_condition', (object) [
                'ruleid' => $ruleid,
                'conditiontype' => 'grade_in_activity',
                'params' => json_encode([
                    'cmid' => $cm->id,
                    'gradeitemsconditions' => $gradeitemsconditions,
                ]),
                'lastexecutiontime' => null,
            ]);

            $DB->insert_record('local_coursedynamicrules_action', (object) [
                'ruleid' => $ruleid,
                'actiontype' => 'sendnotification',
                'params' => json_encode([
                    'messagesubject' => trim($row['subject']),
                    'messagebody' => 'Behat generated body',
                    'primaryroleids' => [],
                    'copyroleids' => [],
                    // This fixture inserts the row directly (bypassing sendnotification_action::
                    // save_action()), so without this key the row is legacy-shaped: FIX3-5 sends it
                    // down the verbatim (unmarked) path instead of the raw/marked one, which would
                    // silently double-format the body when the grade_in_activity edit scenario runs.
                    'bodyisraw' => true,
                ]),
                'lastexecutiontime' => null,
            ]);
        }
    }

    /**
     * Toggle a grade-in-activity threshold checkbox and set its value on the dynamic sub-form.
     *
     * Used by the grade_in_activity edit Behat scenario: the dynamic form's threshold elements are
     * keyed by the real grade item id (`enable{condition}_{gradeitemid}` / `{condition}_
     * {gradeitemid}`), which is only known once the fixture activity has been created, hence
     * resolving it here instead of hardcoding an id in the feature file.
     *
     * @When /^I toggle the "(?P<cond_string>gradegte|gradelt)" threshold for "(?P<act_string>[^"]*)" to "(?P<value_string>[^"]*)"$/
     * @param string $condition Threshold condition (gradegte|gradelt).
     * @param string $activityidnumber Course module idnumber of the graded activity.
     * @param string $value Threshold value to set.
     */
    public function i_toggle_the_grade_threshold_for(string $condition, string $activityidnumber, string $value): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $cm = $DB->get_record('course_modules', ['idnumber' => $activityidnumber], '*', MUST_EXIST);
        $modname = $DB->get_field('modules', 'name', ['id' => $cm->module], MUST_EXIST);
        $gradeitem = \grade_item::fetch([
            'iteminstance' => $cm->instance,
            'itemmodule' => $modname,
            'itemtype' => 'mod',
            'itemnumber' => 0,
        ]);
        if (!$gradeitem) {
            throw new Exception('No grade item found for activity "' . $activityidnumber . '".');
        }

        $key = $condition . '_' . $gradeitem->id;
        $this->execute('behat_forms::i_set_the_field_to', ['enable' . $key, '1']);
        $this->execute('behat_forms::i_set_the_field_to', [$key, $value]);
    }

    /**
     * Resolve comma-separated role shortnames to role ids.
     *
     * @param string $roleshortnames Comma-separated role shortnames.
     * @return int[]
     */
    private function resolve_role_shortnames_to_ids(string $roleshortnames): array {
        global $DB;

        $roleids = [];
        $shortnames = array_filter(array_map('trim', explode(',', $roleshortnames)));
        foreach ($shortnames as $shortname) {
            $roleids[] = (int)$DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        }

        return $roleids;
    }
}
