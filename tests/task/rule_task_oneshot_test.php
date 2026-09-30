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

namespace local_coursedynamicrules\task;

/**
 * Event adhoc task versus the one-shot scheduled pass: one notification per student and arming.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\task\rule_task
 * @covers     \local_coursedynamicrules\core\rule
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_task_oneshot_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /** @var int Student role id. */
    private int $studentroleid;

    /** @var \phpunit_message_sink Message sink. */
    private $sink;

    /**
     * Course with completion, one enrolled student and a message sink.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_user();
        $this->studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, $this->studentroleid);
        $this->sink = $this->redirectMessages();
    }

    /**
     * A page with manual completion.
     *
     * @return \stdClass Course module record.
     */
    private function create_page(): \stdClass {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        return get_coursemodule_from_id('page', $page->cmid, $this->course->id, false, MUST_EXIST);
    }

    /**
     * Mark a page complete for the student and return the completion row id.
     *
     * @param \stdClass $cm Course module.
     * @return int
     */
    private function complete(\stdClass $cm): int {
        global $DB;
        (new \completion_info($this->course))->update_state($cm, COMPLETION_COMPLETE, $this->student->id);
        return (int) $DB->get_field(
            'course_modules_completion',
            'id',
            ['coursemoduleid' => $cm->id, 'userid' => $this->student->id],
            MUST_EXIST
        );
    }

    /**
     * Insert an active rule with the given conditions and a notification to students.
     *
     * @param array $conditions Array of [conditiontype, paramsarray].
     * @return int Rule id.
     */
    private function insert_rule(array $conditions): int {
        global $DB;
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->course->id,
            'name' => 'R4',
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
                'messagesubject' => 'R4 alert',
                'messagebody' => 'body',
                'primaryroleids' => [$this->studentroleid],
                'copyroleids' => [],
            ]),
        ]);
        return $ruleid;
    }

    /**
     * The mixed R4 rule of the manual case, already due: A completed, B not completed.
     *
     * @return array [int ruleid, int completionid of A]
     */
    private function create_due_mixed_rule(): array {
        $cma = $this->create_page();
        $cmb = $this->create_page();
        $completionid = $this->complete($cma);
        $ruleid = $this->insert_rule([
            ['complete_activity', ['cmid' => $cma->id]],
            ['no_complete_activity', ['cmid' => $cmb->id, 'expectedcompletiondate' => time() - HOURSECS]],
        ]);
        return [$ruleid, $completionid];
    }

    /**
     * Run the adhoc task the completion observer queues for the student.
     *
     * @param int $completionid Completion row id carried by the event.
     */
    private function run_event_task(int $completionid): void {
        rule_task::instance((object) [
            'courseid' => $this->course->id,
            'userid' => $this->student->id,
            'conditiontypes' => ['complete_activity'],
            'completionid' => $completionid,
        ])->execute();
    }

    /**
     * Run the scheduled one-shot pass, discarding its report output.
     */
    private function run_pass(): void {
        ob_start();
        (new no_complete_activity_task())->execute();
        ob_end_clean();
    }

    /**
     * Notifications the student received so far.
     *
     * @return int
     */
    private function count_messages(): int {
        $messages = $this->sink->get_messages_by_component('local_coursedynamicrules');
        return count(array_filter($messages, fn($m) => $m->useridto == $this->student->id));
    }

    /**
     * MDL-INT-021: an event after the date, then the pass: exactly one notification, rule executed.
     */
    public function test_event_then_pass_notify_once_per_arming(): void {
        global $DB;
        [$ruleid, $completionid] = $this->create_due_mixed_rule();

        $this->run_event_task($completionid);
        $this->run_pass();

        $this->assertSame(1, $this->count_messages());
        $rule = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(0, $rule->active);
        $this->assertNotEmpty($rule->timeautodeactivated);
    }

    /**
     * MDL-INT-021: a rule re-armed after its pass notifies exactly once more in the new arming.
     */
    public function test_rearmed_rule_notifies_once_in_the_new_arming(): void {
        global $DB;
        [$ruleid, $completionid] = $this->create_due_mixed_rule();
        $this->run_pass();
        $this->assertSame(1, $this->count_messages(), 'Sanity: the first arming notified once.');

        $DB->update_record('local_coursedynamicrules_rule', (object) [
            'id' => $ruleid,
            'active' => 1,
            'timeautodeactivated' => null,
        ]);
        $this->run_event_task($completionid);
        $this->run_pass();

        $this->assertSame(2, $this->count_messages());
        $this->assertEquals(0, $DB->get_field('local_coursedynamicrules_rule', 'active', ['id' => $ruleid]));
    }

    /**
     * MDL-INT-021: a rule without a one-shot condition keeps firing on the event path.
     */
    public function test_event_path_keeps_firing_rules_without_one_shot_conditions(): void {
        $cma = $this->create_page();
        $completionid = $this->complete($cma);
        $this->insert_rule([['complete_activity', ['cmid' => $cma->id]]]);

        $this->run_event_task($completionid);

        $this->assertSame(1, $this->count_messages());
    }
}
