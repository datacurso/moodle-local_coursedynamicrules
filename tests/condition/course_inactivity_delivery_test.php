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

namespace local_coursedynamicrules\condition;

use local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\delivery_ledger;
use local_coursedynamicrules\helper\rule_duplicator;

/**
 * The inactivity delivery ledger: one delivery per condition, student, anchor and milestone.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition
 * @covers     \local_coursedynamicrules\helper\delivery_ledger
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_inactivity_delivery_test extends \advanced_testcase {
    /** @var array event-family condition types passed by the completion observer. */
    private const EVENT_TYPES = ['complete_activity', 'grade_in_activity', 'passgrade'];

    /**
     * Insert an active rule with the given condition rows plus a notification to students.
     *
     * @param int $courseid Course id.
     * @param array $conditions Array of [conditiontype, paramsarray], inserted in that order.
     * @return \stdClass The rule row.
     */
    private function insert_rule(int $courseid, array $conditions): \stdClass {
        global $DB;

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Inactivity milestone',
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
                'messagesubject' => 'Milestone alert',
                'messagebody' => 'body',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);

        return $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
    }

    /**
     * Params of a recurring one-day inactivity condition measured from the course start.
     *
     * @return array
     */
    private static function recurring_daily(): array {
        return [
            'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
            'timeintervals' => '1',
            'intervalunit' => 'days',
            'basedatetype' => course_inactivity_condition::DATE_FROM_COURSE_START,
        ];
    }

    /**
     * The stored inactivity condition of a rule, built with its own clock.
     *
     * @param \stdClass $rule The rule row.
     * @param int $now The condition's current time.
     * @return course_inactivity_condition
     */
    private function load_condition(\stdClass $rule, int $now): course_inactivity_condition {
        global $DB;

        $record = $DB->get_record(
            'local_coursedynamicrules_condition',
            ['ruleid' => $rule->id, 'conditiontype' => 'course_inactivity'],
            '*',
            MUST_EXIST
        );

        return new course_inactivity_condition($record, (int) $rule->courseid, $now);
    }

    /**
     * Notifications the sink holds for one user.
     *
     * @param \phpunit_message_sink $sink The sink.
     * @param int $userid The recipient.
     * @return int
     */
    private function count_to(\phpunit_message_sink $sink, int $userid): int {
        return count(array_filter(
            $sink->get_messages_by_component('local_coursedynamicrules'),
            static fn($m) => (int) $m->useridto === $userid
        ));
    }

    /**
     * Rows of the ledger matching the given fields.
     *
     * @param array $conditions Field => value.
     * @return int
     */
    private function deliveries(array $conditions = []): int {
        global $DB;
        return $DB->count_records(delivery_ledger::TABLE, $conditions);
    }

    /**
     * MDL-UNIT-010: a delivered milestone stops meeting the condition until the next milestone.
     */
    public function test_next_recurring_milestone_delivers_again(): void {
        $this->resetAfterTest(true);
        $now = time();
        $course = $this->getDataGenerator()->create_course(['startdate' => $now - (2 * DAYSECS) - (2 * HOURSECS)]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $rule = $this->insert_rule((int) $course->id, [['course_inactivity', self::recurring_daily()]]);
        $context = (object) ['courseid' => (int) $course->id, 'userid' => (int) $student->id, 'additionaldata' => []];

        $condition = $this->load_condition($rule, $now);
        $this->assertTrue($condition->evaluate($context), 'The open window must meet the condition.');
        $condition->actions_executed($context);
        $this->assertSame(1, $this->deliveries(['userid' => $student->id]));

        $this->assertFalse($this->load_condition($rule, $now)->evaluate($context), 'A delivered milestone must not meet it again.');
        $this->assertFalse(
            $this->load_condition($rule, $now + (5 * HOURSECS))->evaluate($context),
            'Later in the same window it is still the delivered milestone.'
        );

        $next = $this->load_condition($rule, $now + DAYSECS);
        $this->assertTrue($next->evaluate($context), 'The next recurring milestone is a new delivery.');
        $next->actions_executed($context);
        $this->assertSame(2, $this->deliveries(['userid' => $student->id]));
    }

    /**
     * MDL-UNIT-010 step 5: the same milestone instant measured from another anchor meets the condition again.
     */
    public function test_moving_the_anchor_back_opens_a_new_delivery(): void {
        global $DB;

        $this->resetAfterTest(true);
        $now = time();
        $course = $this->getDataGenerator()->create_course(['startdate' => $now - (2 * DAYSECS) - (2 * HOURSECS)]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $rule = $this->insert_rule((int) $course->id, [['course_inactivity', self::recurring_daily()]]);
        $context = (object) ['courseid' => (int) $course->id, 'userid' => (int) $student->id, 'additionaldata' => []];

        $condition = $this->load_condition($rule, $now);
        $this->assertTrue($condition->evaluate($context));
        $condition->actions_executed($context);

        // One day earlier: the third milestone lands on the instant the second one did.
        $DB->set_field('course', 'startdate', $now - (3 * DAYSECS) - (2 * HOURSECS), ['id' => $course->id]);
        rebuild_course_cache($course->id, true);

        $moved = $this->load_condition($rule, $now);
        $this->assertTrue($moved->evaluate($context), 'Another anchor is another delivery, even at the same instant.');
        $moved->actions_executed($context);
        $this->assertFalse($this->load_condition($rule, $now)->evaluate($context));
        $this->assertSame(2, $this->deliveries(['userid' => $student->id]));
    }

    /**
     * MDL-UNIT-010: nothing is recorded when another condition of the rule keeps the actions from running.
     */
    public function test_delivery_is_recorded_only_when_actions_run(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => time() - (2 * DAYSECS) - (2 * HOURSECS),
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        // Inactivity first, so it is evaluated (true) before the unmet completion stops the AND.
        $rule = $this->insert_rule((int) $course->id, [
            ['course_inactivity', self::recurring_daily()],
            ['complete_activity', ['cmid' => (int) $page->cmid]],
        ]);

        $sink = $this->redirectMessages();
        (new rule($rule, [$student]))->execute();

        $this->assertSame(0, $this->count_to($sink, (int) $student->id));
        $this->assertSame(0, $this->deliveries(), 'An undelivered milestone must stay deliverable.');
    }

    /**
     * MDL-UNIT-005 / MDL-UNIT-010: the event path and the scheduled pass share the milestone delivery.
     */
    public function test_event_and_pass_share_the_milestone_delivery(): void {
        global $DB;

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => time() - (2 * DAYSECS) - (2 * HOURSECS),
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid, $course->id, false, MUST_EXIST);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $completionid = (int) $DB->get_field(
            'course_modules_completion',
            'id',
            ['coursemoduleid' => $cm->id, 'userid' => $student->id],
            MUST_EXIST
        );

        $rule = $this->insert_rule((int) $course->id, [
            ['complete_activity', ['cmid' => (int) $cm->id]],
            ['course_inactivity', self::recurring_daily()],
        ]);

        $sink = $this->redirectMessages();
        (new rule($rule, [$student], self::EVENT_TYPES, ['completionid' => $completionid]))->execute();
        $this->assertSame(1, $this->count_to($sink, (int) $student->id), 'The event delivers the milestone.');

        (new rule($rule, [$student]))->execute();
        $this->assertSame(1, $this->count_to($sink, (int) $student->id), 'The pass must not deliver it again.');
    }

    /**
     * Two rules of one course, each with a delivery for two students; returns the pieces.
     *
     * @return array [stdClass course, stdClass rule, stdClass otherrule, stdClass student, stdClass other]
     */
    private function create_deliveries(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['startdate' => time() - DAYSECS]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $rule = $this->insert_rule((int) $course->id, [['course_inactivity', self::recurring_daily()]]);
        $otherrule = $this->insert_rule((int) $course->id, [['course_inactivity', self::recurring_daily()]]);

        foreach ([$rule, $otherrule] as $r) {
            $conditionid = (int) $DB->get_field('local_coursedynamicrules_condition', 'id', ['ruleid' => $r->id]);
            foreach ([$student, $other] as $u) {
                delivery_ledger::record((int) $r->id, $conditionid, (int) $u->id, delivery_ledger::milestone_key(100, 200));
            }
        }

        return [$course, $rule, $otherrule, $student, $other];
    }

    /**
     * MDL-INT-017: deleting a rule removes its deliveries and only those.
     */
    public function test_deleting_a_rule_removes_its_deliveries(): void {
        $this->resetAfterTest(true);
        [, $rule, $otherrule] = $this->create_deliveries();

        (new rule($rule, []))->delete();

        $this->assertSame(0, $this->deliveries(['ruleid' => $rule->id]));
        $this->assertSame(2, $this->deliveries(['ruleid' => $otherrule->id]));
    }

    /**
     * Deleting a condition removes its deliveries and only those.
     */
    public function test_deleting_a_condition_removes_its_deliveries(): void {
        $this->resetAfterTest(true);
        [, $rule, $otherrule] = $this->create_deliveries();

        $this->load_condition($rule, time())->delete();

        $this->assertSame(0, $this->deliveries(['ruleid' => $rule->id]));
        $this->assertSame(2, $this->deliveries(['ruleid' => $otherrule->id]));
    }

    /**
     * MDL-INT-018: deleting a user removes that user's deliveries and only those.
     */
    public function test_deleting_a_user_removes_their_deliveries(): void {
        $this->resetAfterTest(true);
        [, , , $student, $other] = $this->create_deliveries();

        delete_user($student);

        $this->assertSame(0, $this->deliveries(['userid' => $student->id]));
        $this->assertSame(2, $this->deliveries(['userid' => $other->id]));
    }

    /**
     * Deleting a course removes the deliveries of its rules and only those.
     */
    public function test_deleting_a_course_removes_its_deliveries(): void {
        $this->resetAfterTest(true);
        [$course] = $this->create_deliveries();
        [$keptcourse, $keptrule] = $this->create_deliveries();

        delete_course($course, false);

        $this->assertSame(4, $this->deliveries());
        $this->assertSame(2, $this->deliveries(['ruleid' => $keptrule->id]));
        $this->assertNotEmpty($keptcourse);
    }

    /**
     * MDL-UNIT-025: a duplicated rule starts without deliveries; the source keeps its own.
     */
    public function test_duplicate_does_not_copy_deliveries(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $rule] = $this->create_deliveries();

        $copyid = rule_duplicator::duplicate((int) $rule->id, (int) $course->id, \context_course::instance($course->id));

        $this->assertSame(0, $this->deliveries(['ruleid' => $copyid]));
        $this->assertSame(2, $this->deliveries(['ruleid' => $rule->id]));
    }

    /**
     * Recording the same delivery twice keeps one row and does not throw (concurrent runs).
     */
    public function test_recording_twice_keeps_one_row(): void {
        global $DB;

        $this->resetAfterTest(true);
        [, $rule, , $student] = $this->create_deliveries();
        $conditionid = (int) $DB->get_field('local_coursedynamicrules_condition', 'id', ['ruleid' => $rule->id]);

        delivery_ledger::record((int) $rule->id, $conditionid, (int) $student->id, delivery_ledger::milestone_key(100, 200));

        $this->assertSame(1, $this->deliveries(['conditionid' => $conditionid, 'userid' => $student->id]));
        $userid = (int) $student->id;
        $this->assertTrue(delivery_ledger::is_delivered($conditionid, $userid, delivery_ledger::milestone_key(100, 200)));
        $this->assertFalse(delivery_ledger::is_delivered($conditionid, $userid, delivery_ledger::milestone_key(99, 200)));
    }
}
