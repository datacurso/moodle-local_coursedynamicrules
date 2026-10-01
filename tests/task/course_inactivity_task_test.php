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
     * Build a course started at $startdate, one enrolled student who never entered it, and an
     * active rule holding one inactivity condition (course-start base) and a notification.
     *
     * @param int $startdate Course start date.
     * @param string $intervaltype 'custom' or 'recurring'.
     * @param string $timeintervals Interval value(s), in days.
     * @return array [stdClass course, stdClass student]
     */
    private function create_inactivity_rule(int $startdate, string $intervaltype, string $timeintervals): array {
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
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'course_inactivity',
            'params' => json_encode([
                'intervaltype' => $intervaltype,
                'timeintervals' => $timeintervals,
                'intervalunit' => 'days',
                'basedatetype' => 'coursestart',
            ]),
        ]);
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

        return [$course, $student];
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
     * MDL-UNIT-010 step 5: moving the course start back re-anchors the milestones and notifies again.
     *
     * With the start one day earlier the recurring milestone falls on the very same instant as
     * before, but measured from another anchor: that is a new delivery, and it is delivered once.
     */
    public function test_moving_the_course_start_back_notifies_again_once(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, $student] = $this->create_inactivity_rule(time() - (2 * DAYSECS) - (2 * HOURSECS), 'recurring', '1');

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertSame(1, $this->count_to($sink, (int) $student->id));

        $DB->set_field('course', 'startdate', time() - (3 * DAYSECS) - (2 * HOURSECS), ['id' => $course->id]);
        rebuild_course_cache($course->id, true);

        $this->run_task();
        $this->assertSame(2, $this->count_to($sink, (int) $student->id), 'The re-anchored milestone must notify.');

        $this->run_task();
        $this->assertSame(2, $this->count_to($sink, (int) $student->id), 'And only once.');
    }
}
