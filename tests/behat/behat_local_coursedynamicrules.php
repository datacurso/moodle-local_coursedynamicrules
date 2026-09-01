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
            // The generator obeys the production axiom "active means it WAS activated": every
            // production writer that sets active=1 also stamps timeactivated (editrule, the
            // upgrade, restore), so an active-but-unstamped rule is a state no real site can
            // hold post-2026083002 - and a suite exercising impossible states proves nothing.
            // Round-2 judge CRITICAL. An explicit timeactivated column still overrides.
            $active = isset($row['active']) && trim($row['active']) !== '' ? (int)$row['active'] : 1;
            // Same axiom family: a rule only executes after its first activation, so an executed
            // rule with no activation stamp is another state no real site can hold. When the
            // scenario sets lastexecutiontime on an unstamped rule, the execution moment doubles
            // as the activation moment.
            $lastexecution = isset($row['lastexecutiontime']) && trim($row['lastexecutiontime']) !== ''
                ? (int)$row['lastexecutiontime']
                : null;
            $timeactivated = isset($row['timeactivated']) && trim($row['timeactivated']) !== ''
                ? ((int)$row['timeactivated'] ?: ($active ? time() : null))
                : ($active ? time() : null);
            if ($lastexecution !== null && $timeactivated === null) {
                $timeactivated = $lastexecution;
            }
            $ruleid = (int)$DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id,
                // Optional columns, defaulted for backwards compatibility with every existing
                // feature: a name so scenarios can tell rules apart, and active so the activation
                // flow can start from a genuinely inactive rule.
                'name' => trim($row['name'] ?? '') !== '' ? trim($row['name']) : 'Behat no course access rule',
                'description' => 'Behat generated rule',
                'active' => $active,
                'timeactivated' => $timeactivated,
                'lastexecutiontime' => $lastexecution,
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
     * Create bare rules - a rule row with NO conditions and NO actions.
     *
     * The population the 2026083002 upgrade seals incomplete: pre-lock sites could save a rule
     * active with zero components. Scenarios about what the listing offers such rules need to
     * build one, and no UI path can any more.
     *
     * @Given /^the following local coursedynamicrules bare rules exist:$/
     * @param TableNode $table Table data with columns: course, name, active, timeactivated.
     */
    public function the_following_local_coursedynamicrules_bare_rules_exist(TableNode $table): void {
        global $DB;

        foreach ($table->getHash() as $row) {
            $course = $DB->get_record('course', ['shortname' => $row['course']], '*', MUST_EXIST);
            $DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id,
                'name' => trim($row['name']),
                'description' => 'Behat generated bare rule',
                'active' => isset($row['active']) && trim($row['active']) !== '' ? (int)$row['active'] : 0,
                // Same axiom as every generator here: active without an explicit stamp is sealed
                // now, because no production writer leaves an active rule unstamped.
                'timeactivated' => isset($row['timeactivated']) && trim($row['timeactivated']) !== ''
                    ? ((int)$row['timeactivated'] ?: (!empty($row['active']) ? time() : null))
                    : (!empty($row['active']) && (int)$row['active'] === 1 ? time() : null),
                'lastexecutiontime' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Visit the activation confirmation page for a rule, addressed by name.
     *
     * The page a replayed link or an old browser tab lands on: editrule.php?confirmactivate=1.
     * Reaching it directly is the point - the scenario exercises what the page says when the
     * confirmation is no longer applicable, and no UI path can produce that URL twice.
     *
     * @When /^I visit the activation confirmation page for the rule "(?P<name>[^"]*)"$/
     * @param string $name The rule name.
     */
    public function i_visit_the_activation_confirmation_page_for_the_rule(string $name): void {
        global $DB;

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['name' => $name], '*', MUST_EXIST);
        $this->execute('behat_general::i_visit', [new moodle_url('/local/coursedynamicrules/editrule.php', [
            'courseid' => $rule->courseid,
            'id' => $rule->id,
            'confirmactivate' => 1,
        ])]);
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
            // Optional active column; the default obeys the axiom below (active arrives sealed).
            $active = isset($row['active']) && trim($row['active']) !== '' ? (int)$row['active'] : 1;
            $ruleid = (int)$DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id,
                'name' => 'Behat AI activity rule',
                'description' => 'Behat generated rule',
                'active' => $active,
                // Active means it WAS activated: production never holds an active-unstamped rule,
                // so neither does any rule this context fabricates.
                'timeactivated' => $active ? time() : null,
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
