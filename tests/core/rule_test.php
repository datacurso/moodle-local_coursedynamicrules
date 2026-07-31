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

namespace local_coursedynamicrules\core;

/**
 * Tests for the rule condition-combination (AND) semantics across trigger paths.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\core\rule
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_test extends \advanced_testcase {
    /** @var array event-family condition types passed by the completion observer. */
    private const EVENT_TYPES = ['complete_activity', 'grade_in_activity', 'passgrade'];

    /**
     * Create a module with manual completion and mark the given user complete.
     *
     * @param \stdClass $course Course.
     * @param int $userid User id.
     * @return array [cm_info-like cm, int completionid]
     */
    private function create_completed_activity(\stdClass $course, int $userid): array {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid, $course->id, false, MUST_EXIST);

        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $userid);

        $completionid = (int) $DB->get_field(
            'course_modules_completion',
            'id',
            ['coursemoduleid' => $cm->id, 'userid' => $userid],
            MUST_EXIST
        );

        return [$cm, $completionid];
    }

    /**
     * Insert a rule with the given condition rows plus a notification action targeting students.
     *
     * @param int $courseid Course id.
     * @param array $conditions Array of [conditiontype, paramsarray].
     * @param int $studentroleid Student role id.
     * @return \stdClass The rule DB record.
     */
    private function insert_rule(int $courseid, array $conditions, int $studentroleid): \stdClass {
        global $DB;
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Combined',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        foreach ($conditions as [$type, $params]) {
            $DB->insert_record('local_coursedynamicrules_condition', (object) [
                'ruleid' => $ruleid,
                'conditiontype' => $type,
                'params' => json_encode($params),
            ]);
        }
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Combined alert',
                'messagebody' => 'body',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);
        return $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
    }

    /**
     * Count notifications sent to a user through the given sink.
     *
     * @param \phpunit_message_sink $sink Message sink.
     * @param int $userid Recipient user id.
     * @return int
     */
    private function count_messages($sink, int $userid): int {
        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        return count(array_filter($messages, function ($m) use ($userid) {
            return $m->useridto == $userid;
        }));
    }

    /**
     * Set up a course and enrolled student.
     *
     * @return array [stdClass course, stdClass student, int studentroleid]
     */
    private function setup_course_student(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentroleid);
        return [$course, $student, (int) $studentroleid];
    }

    /**
     * On the event path a mixed rule must NOT fire when a non-event condition is unmet.
     */
    public function test_event_path_respects_unmet_cron_condition(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, $student, $studentroleid] = $this->setup_course_student();
        [$cm, $completionid] = $this->create_completed_activity($course, $student->id);

        // Completion done (condition A true) but the user accessed just now (no-access condition B false).
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id, 'courseid' => $course->id, 'timeaccess' => time(),
        ]);

        $rule = $this->insert_rule($course->id, [
            ['complete_activity', ['cmid' => $cm->id]],
            ['no_course_access', ['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => 0]],
        ], $studentroleid);

        $sink = $this->redirectMessages();
        $ruleinstance = new rule($rule, [$student], self::EVENT_TYPES, ['completionid' => $completionid]);
        $ruleinstance->execute();

        $this->assertSame(0, $this->count_messages($sink, $student->id));
    }

    /**
     * On the event path a mixed rule fires when all conditions are met.
     */
    public function test_event_path_fires_when_all_met(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, $student, $studentroleid] = $this->setup_course_student();
        [$cm, $completionid] = $this->create_completed_activity($course, $student->id);

        // Completion done (A true); last access was long ago so no-access (B) is also true.
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id, 'courseid' => $course->id, 'timeaccess' => time() - (40 * DAYSECS),
        ]);
        $rule = $this->insert_rule($course->id, [
            ['complete_activity', ['cmid' => $cm->id]],
            ['no_course_access', ['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => 0]],
        ], $studentroleid);

        $sink = $this->redirectMessages();
        $ruleinstance = new rule($rule, [$student], self::EVENT_TYPES, ['completionid' => $completionid]);
        $ruleinstance->execute();

        $this->assertSame(1, $this->count_messages($sink, $student->id));
    }

    /**
     * Two conditions on different activities must both be evaluated (rule fires when both hold).
     */
    public function test_two_activity_conditions_both_evaluated(): void {
        $this->resetAfterTest(true);
        [$course, $student, $studentroleid] = $this->setup_course_student();
        [$cm1, $completionid1] = $this->create_completed_activity($course, $student->id);
        [$cm2] = $this->create_completed_activity($course, $student->id);

        $rule = $this->insert_rule($course->id, [
            ['complete_activity', ['cmid' => $cm1->id]],
            ['complete_activity', ['cmid' => $cm2->id]],
        ], $studentroleid);

        // Event for activity 1; activity 2 is also complete.
        $sink = $this->redirectMessages();
        $ruleinstance = new rule($rule, [$student], self::EVENT_TYPES, ['completionid' => $completionid1]);
        $ruleinstance->execute();

        $this->assertSame(1, $this->count_messages($sink, $student->id));
    }

    /**
     * A grade event whose grade row no longer exists must not raise a PHP error.
     *
     * The grade row can be deleted between the event dispatch and the adhoc task run; resolving
     * the cmid from a missing grade id must degrade to "not relevant" (rule does not fire) instead
     * of dereferencing a false record and throwing an \Error the task cannot catch.
     */
    public function test_event_path_with_stale_gradeid_does_not_error(): void {
        $this->resetAfterTest(true);
        [$course, $student, $studentroleid] = $this->setup_course_student();
        [$cm] = $this->create_completed_activity($course, $student->id);

        $rule = $this->insert_rule($course->id, [
            ['complete_activity', ['cmid' => $cm->id]],
        ], $studentroleid);

        // Event carries a grade id that does not resolve to any grade row.
        $sink = $this->redirectMessages();
        $ruleinstance = new rule($rule, [$student], self::EVENT_TYPES, ['gradeid' => 999999]);
        $ruleinstance->execute();

        $this->assertSame(0, $this->count_messages($sink, $student->id));
    }

    /**
     * An event on an unrelated activity must not fire a rule that does not reference it.
     */
    public function test_unrelated_event_does_not_fire(): void {
        $this->resetAfterTest(true);
        [$course, $student, $studentroleid] = $this->setup_course_student();
        [$cm1] = $this->create_completed_activity($course, $student->id);
        [, $completionid2] = $this->create_completed_activity($course, $student->id);

        // Rule references activity 1 only (and it is complete); the event is a completion on activity 2.
        $rule = $this->insert_rule($course->id, [
            ['complete_activity', ['cmid' => $cm1->id]],
        ], $studentroleid);

        $sink = $this->redirectMessages();
        $ruleinstance = new rule($rule, [$student], self::EVENT_TYPES, ['completionid' => $completionid2]);
        $ruleinstance->execute();

        $this->assertSame(0, $this->count_messages($sink, $student->id));
    }
}
