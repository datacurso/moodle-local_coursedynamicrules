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

namespace local_coursedynamicrules\observer;

use local_coursedynamicrules\task\rule_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * One grading operation is evaluated once per activity and student (MDL-UNIT-012).
 *
 * Saving the two grade items of one activity (for example a forum's rating and its whole-forum
 * grade) in a single gradebook save fires one user_graded event per item. Each event used to queue
 * its own evaluation, keyed by the grade row, so a rule with a threshold on each item notified the
 * student twice in the same second. The evaluation is now keyed by the activity and delayed by a
 * short window, so the second event finds the first evaluation still queued and adds nothing.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\observer\user_graded
 * @covers     \local_coursedynamicrules\task\rule_task
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_graded_coalescing_test extends \advanced_testcase {
    /**
     * Create an auto-completion assignment that holds two grade items (itemnumber 0 and 1).
     *
     * @param \stdClass $course Course.
     * @return array{0: int, 1: \grade_item, 2: \grade_item} cmid, item 0, item 1.
     */
    private function create_two_item_activity(\stdClass $course): array {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'grade' => 100,
        ]);
        // A second grade item on the same activity, like a forum's whole-forum grade next to its rating.
        grade_update('mod/assign', $course->id, 'mod', 'assign', $assign->id, 1, null, [
            'itemname' => 'Second item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
        ]);

        $items = [];
        foreach ([0, 1] as $itemnumber) {
            $items[$itemnumber] = \grade_item::fetch([
                'courseid' => $course->id,
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'iteminstance' => $assign->id,
                'itemnumber' => $itemnumber,
            ]);
        }

        return [(int) $assign->cmid, $items[0], $items[1]];
    }

    /**
     * Insert an active rule "item 0 >= 50 and item 1 >= 50" on the activity, notifying the student.
     *
     * @param int $courseid Course id.
     * @param int $cmid Activity.
     * @param array $items Grade items keyed by itemnumber.
     * @return int Rule id.
     */
    private function insert_two_threshold_rule(int $courseid, int $cmid, array $items): int {
        global $DB;
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Two items',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $conditions = [];
        foreach ($items as $itemnumber => $item) {
            $conditions['gradegte_' . $itemnumber] = [
                'gradeitem' => (int) $item->id,
                'itemnumber' => $itemnumber,
                'condition' => 'gradegte',
                'value' => 50,
            ];
        }
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'grade_in_activity',
            'params' => json_encode(['cmid' => $cmid, 'gradeitemsconditions' => $conditions]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Two items alert',
                'messagebody' => 'body',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);
        return $ruleid;
    }

    /**
     * Queued rule_task records.
     *
     * @return \stdClass[]
     */
    private function queued_rule_tasks(): array {
        global $DB;
        return array_values($DB->get_records('task_adhoc', ['classname' => '\\' . rule_task::class]));
    }

    /**
     * Count this plugin's notifications to a user in the sink.
     *
     * @param \phpunit_message_sink $sink Sink.
     * @param int $userid Recipient.
     * @return int
     */
    private function count_messages($sink, int $userid): int {
        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        return count(array_filter($messages, static fn($m) => (int) $m->useridto === $userid));
    }

    /**
     * Course, student, two-item activity and the rule over it.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: int, 3: \grade_item, 4: \grade_item}
     */
    private function setup_scenario(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        [$cmid, $item0, $item1] = $this->create_two_item_activity($course);
        $this->insert_two_threshold_rule((int) $course->id, $cmid, [0 => $item0, 1 => $item1]);
        return [$course, $student, $cmid, $item0, $item1];
    }

    /**
     * Two grades of one activity for one student queue a single evaluation.
     */
    public function test_two_grades_of_one_activity_enqueue_one_evaluation(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [, $student, $cmid, $item0, $item1] = $this->setup_scenario();

        $item0->update_final_grade($student->id, 70);
        $item1->update_final_grade($student->id, 70);

        $queued = $this->queued_rule_tasks();
        $this->assertCount(
            1,
            $queued,
            'The two grade items of one activity, saved together, must queue one evaluation, not one per item.'
        );
        $this->assertSame($cmid, (int) json_decode($queued[0]->customdata)->cmid);
    }

    /**
     * Grades of two different activities still queue one evaluation each.
     */
    public function test_grades_of_two_activities_enqueue_two_evaluations(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $student, , $item0] = $this->setup_scenario();
        [, $otheritem] = $this->create_two_item_activity($course);

        $item0->update_final_grade($student->id, 70);
        $otheritem->update_final_grade($student->id, 70);

        $this->assertCount(2, $this->queued_rule_tasks(), 'Each activity must keep its own evaluation.');
    }

    /**
     * The same grade item for two students queues one evaluation each.
     */
    public function test_grades_of_two_users_enqueue_two_evaluations(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [$course, $student, , $item0] = $this->setup_scenario();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $item0->update_final_grade($student->id, 70);
        $item0->update_final_grade($other->id, 70);

        $this->assertCount(2, $this->queued_rule_tasks(), 'Each student must keep their own evaluation.');
    }

    /**
     * The evaluation waits for the coalescing window, so a second grade written a moment later in
     * the same save still finds it queued (not already taken by cron).
     */
    public function test_grade_evaluation_waits_for_the_coalesce_window(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $clock = $this->mock_clock_with_frozen();
        [, $student, , $item0] = $this->setup_scenario();

        $item0->update_final_grade($student->id, 70);

        $queued = $this->queued_rule_tasks();
        $this->assertCount(1, $queued);
        $this->assertGreaterThanOrEqual(
            $clock->time() + rule_task::COALESCE_DELAY,
            (int) $queued[0]->nextruntime,
            'The grade evaluation must be delayed by the coalescing window.'
        );
    }

    /**
     * One save of both items notifies the student once.
     */
    public function test_one_grading_operation_notifies_once(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [, $student, , $item0, $item1] = $this->setup_scenario();

        $item0->update_final_grade($student->id, 70);
        $item1->update_final_grade($student->id, 70);

        $sink = $this->redirectMessages();
        $this->runAdhocTasks(rule_task::class);

        $this->assertSame(1, $this->count_messages($sink, (int) $student->id), 'One grading operation, one notification.');
    }

    /**
     * After the queued evaluation ran, a later regrade queues and notifies again (MDL-UNIT-012
     * steps 9-10: regrading must keep notifying).
     */
    public function test_a_later_regrade_notifies_again(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        [, $student, , $item0, $item1] = $this->setup_scenario();

        $item0->update_final_grade($student->id, 70);
        $item1->update_final_grade($student->id, 70);
        $sink = $this->redirectMessages();
        $this->runAdhocTasks(rule_task::class);
        $this->assertSame(1, $this->count_messages($sink, (int) $student->id));
        $this->assertCount(0, $this->queued_rule_tasks(), 'The run must drain the queue.');

        // A later regrade of one item, still meeting both thresholds.
        $item0->update_final_grade($student->id, 85);
        $this->assertCount(1, $this->queued_rule_tasks(), 'A regrade after the run must queue a new evaluation.');
        $this->runAdhocTasks(rule_task::class);

        $this->assertSame(2, $this->count_messages($sink, (int) $student->id), 'A later regrade must notify again.');
    }
}
