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
 * Tests for the no_course_access scheduled task user selection.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\task\no_course_access_task
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_course_access_task_test extends \advanced_testcase {
    /**
     * Create an active no_course_access rule that notifies students, due to run now.
     *
     * @param int $courseid Course id.
     * @param int $studentroleid Student role id.
     * @return void
     */
    private function create_rule(int $courseid, int $studentroleid): void {
        global $DB;

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'No access',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'no_course_access',
            // nexttimeperiod in the past so the rule is due immediately.
            'params' => json_encode(['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => time() - 100]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'No access alert',
                'messagebody' => 'You have not accessed the course.',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);
    }

    /**
     * A user enrolled through two methods must be notified only once.
     */
    public function test_dual_enrolled_user_is_notified_once(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        // Two active enrolments (manual + self) for the same user.
        $manual = enrol_get_plugin('manual');
        $minstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->enrol_user($minstance, $student->id, $studentroleid);
        $self = enrol_get_plugin('self');
        $selfid = $self->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED, 'roleid' => $studentroleid]);
        $self->enrol_user($DB->get_record('enrol', ['id' => $selfid], '*', MUST_EXIST), $student->id, $studentroleid);

        // Last access was long ago so the no-access condition is genuinely met.
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $student->id, 'courseid' => $course->id, 'timeaccess' => time() - (40 * DAYSECS),
        ]);

        $this->create_rule($course->id, $studentroleid);

        $sink = $this->redirectMessages();
        ob_start();
        (new no_course_access_task())->execute();
        ob_end_clean();

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $tostudent = array_filter($messages, function ($m) use ($student) {
            return $m->useridto == $student->id;
        });

        $this->assertCount(1, $tostudent);
    }

    /**
     * A user with a suspended enrolment must not be notified.
     */
    public function test_suspended_user_is_not_notified(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $manual = enrol_get_plugin('manual');
        $minstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->enrol_user($minstance, $student->id, $studentroleid, 0, 0, ENROL_USER_SUSPENDED);

        $this->create_rule($course->id, $studentroleid);

        $sink = $this->redirectMessages();
        ob_start();
        (new no_course_access_task())->execute();
        ob_end_clean();

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $tostudent = array_filter($messages, function ($m) use ($student) {
            return $m->useridto == $student->id;
        });

        $this->assertCount(0, $tostudent);
    }
}
