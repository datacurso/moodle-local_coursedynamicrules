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
 * Tests for the course_inactivity scheduled task user selection.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\task\course_inactivity_task
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_inactivity_task_test extends \advanced_testcase {
    /**
     * MDL-INT-006: the inactivity pass evaluates each active non-completing enrolee once, not per enrolment method.
     *
     * A user enrolled through two methods must be notified only once for course inactivity.
     */
    public function test_dual_enrolled_user_is_notified_once(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Course started 7 days ago so a 7-day custom interval window ends now.
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1,
            'startdate' => time() - (7 * DAYSECS),
        ]);
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        // Two active enrolments (manual + self) for the same user; never accessed the course.
        $manual = enrol_get_plugin('manual');
        $minstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->enrol_user($minstance, $student->id, $studentroleid);
        $self = enrol_get_plugin('self');
        $selfid = $self->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED, 'roleid' => $studentroleid]);
        $self->enrol_user($DB->get_record('enrol', ['id' => $selfid], '*', MUST_EXIST), $student->id, $studentroleid);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Inactivity',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'course_inactivity',
            'params' => json_encode([
                'intervaltype' => 'custom',
                'timeintervals' => '7',
                'intervalunit' => 'days',
                'basedatetype' => 'coursestart',
            ]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Inactivity alert',
                'messagebody' => 'You have been inactive.',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);

        $sink = $this->redirectMessages();
        // The task reports what it evaluated, as its siblings do; the run's own log is not what
        // this test is about (course_inactivity_observability_test covers it).
        ob_start();
        (new course_inactivity_task())->execute();
        ob_end_clean();

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $tostudent = array_filter($messages, function ($m) use ($student) {
            return $m->useridto == $student->id;
        });

        $this->assertCount(1, $tostudent);
    }

    /**
     * Build a course started at $startdate with one student who never entered it, and an active rule
     * holding one inactivity condition (course-start base), any extra conditions, and a notification
     * to students.
     *
     * @param int $startdate Course start date.
     * @param string $intervaltype 'custom' or 'recurring'.
     * @param string $timeintervals Interval value(s), in days.
     * @param array $extraconditions Further conditions, as [conditiontype, params array] pairs.
     * @return array [stdClass course, stdClass student, int rule id]
     */
    private function create_inactivity_rule(
        int $startdate,
        string $intervaltype,
        string $timeintervals,
        array $extraconditions = []
    ): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'startdate' => $startdate]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Inactivity milestone',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $conditions = array_merge([['course_inactivity', [
            'intervaltype' => $intervaltype,
            'timeintervals' => $timeintervals,
            'intervalunit' => 'days',
            'basedatetype' => 'coursestart',
        ]]], $extraconditions);
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
                'messagebody' => 'You have been inactive.',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);

        return [$course, $student, (int) $ruleid];
    }

    /**
     * Run the inactivity task once, discarding its report.
     *
     * @return void
     */
    private function run_task(): void {
        ob_start();
        (new course_inactivity_task())->execute();
        ob_end_clean();
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
     * MDL-UNIT-010: a second run inside the same window does not repeat a custom milestone.
     *
     * The milestone fell due two hours ago, so both runs sit inside its six-hour window; only the
     * first may notify.
     */
    public function test_second_run_in_the_same_window_does_not_repeat_custom_milestone(): void {
        $this->resetAfterTest(true);
        [, $student] = $this->create_inactivity_rule(time() - (2 * DAYSECS) - (2 * HOURSECS), 'custom', '2');

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertSame(1, $this->count_to($sink, (int) $student->id), 'The open window must notify once.');

        $this->run_task();
        $this->assertSame(1, $this->count_to($sink, (int) $student->id), 'A second run in the same window must not repeat.');
    }

    /**
     * MDL-UNIT-010: a second run inside the same window does not repeat a recurring milestone.
     */
    public function test_second_run_in_the_same_window_does_not_repeat_recurring_milestone(): void {
        $this->resetAfterTest(true);
        [, $student] = $this->create_inactivity_rule(time() - (2 * DAYSECS) - (2 * HOURSECS), 'recurring', '1');

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->run_task();

        $this->assertSame(1, $this->count_to($sink, (int) $student->id));
    }

    /**
     * MDL-UNIT-010: a run stamps its conditions with the moment it started, not the one it ended.
     *
     * A milestone falling due while a long run walks the course was not evaluated by that run; an
     * end-of-run stamp would put it behind the next run's horizon and it would never be notified.
     * The users are walked by a generator that lets a second pass, standing in for a long run.
     */
    public function test_a_run_stamps_its_conditions_with_its_start(): void {
        global $DB;

        $this->resetAfterTest(true);
        [, $student, $ruleid] = $this->create_inactivity_rule(time() - (2 * DAYSECS) - (2 * HOURSECS), 'custom', '2');
        $record = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);

        $runstart = time();
        $users = (function () use ($student) {
            yield $student;
            $this->waitForSecond();
        })();
        $this->redirectMessages();
        (new \local_coursedynamicrules\core\rule($record, $users))->execute();
        $runend = time();

        $stamp = (int) $DB->get_field('local_coursedynamicrules_condition', 'lastexecutiontime', ['ruleid' => $ruleid]);
        $this->assertGreaterThan($runstart, $runend, 'Sanity: the run must have lasted past its first second.');
        $this->assertGreaterThanOrEqual($runstart, $stamp);
        $this->assertLessThan($runend, $stamp, 'The stamp must be the start of the run.');
    }

    /**
     * MDL-UNIT-010: an event on a mixed rule does not block the inactivity milestone for others.
     *
     * The completion event of one student evaluates the rule for that student alone. Stamping the
     * inactivity condition then would put a milestone already due behind the scheduled pass's
     * horizon, and every other student would miss it.
     */
    public function test_an_event_on_a_mixed_rule_does_not_block_the_milestone_for_other_students(): void {
        global $DB;

        $this->resetAfterTest(true);
        // The inactive student (B) is the one the helper enrols; A is active in the course.
        [$course, $inactive, $ruleid] = $this->create_inactivity_rule(time() - (2 * DAYSECS) - (2 * HOURSECS), 'custom', '2');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid, $course->id, false, MUST_EXIST);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => $cm->id]),
        ]);
        $active = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->get_plugin_generator('local_coursedynamicrules')
            ->create_user_lastaccess($active->id, $course->id, time());

        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $inactive->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $active->id);
        $completionid = (int) $DB->get_field(
            'course_modules_completion',
            'id',
            ['coursemoduleid' => $cm->id, 'userid' => $active->id],
            MUST_EXIST
        );

        $sink = $this->redirectMessages();
        rule_task::instance((object) [
            'courseid' => $course->id,
            'userid' => $active->id,
            'conditiontypes' => ['complete_activity'],
            'completionid' => $completionid,
        ])->execute();
        $this->run_task();

        $this->assertSame(1, $this->count_to($sink, (int) $inactive->id), 'The milestone must still reach the inactive student.');
        $this->assertSame(0, $this->count_to($sink, (int) $active->id));
    }

    /**
     * MDL-UNIT-010: a duplicated rule never ran, so its first run notifies as the original's did.
     */
    public function test_a_duplicated_rule_notifies_as_a_first_run(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, $student, $ruleid] = $this->create_inactivity_rule(time() - (2 * DAYSECS) - (2 * HOURSECS), 'custom', '2');

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertSame(1, $this->count_to($sink, (int) $student->id), 'Sanity: the original notified.');

        $copyid = \local_coursedynamicrules\helper\rule_duplicator::duplicate(
            $ruleid,
            (int) $course->id,
            \context_course::instance($course->id)
        );
        $this->assertNull($DB->get_field('local_coursedynamicrules_condition', 'lastexecutiontime', ['ruleid' => $copyid]));
        $DB->set_field('local_coursedynamicrules_rule', 'active', 1, ['id' => $copyid]);

        $this->run_task();

        $this->assertSame(2, $this->count_to($sink, (int) $student->id), 'The copy notifies once; the original does not repeat.');
    }
}
