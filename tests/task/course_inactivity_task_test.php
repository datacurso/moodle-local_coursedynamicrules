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
        (new course_inactivity_task())->execute();

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $tostudent = array_filter($messages, function ($m) use ($student) {
            return $m->useridto == $student->id;
        });

        $this->assertCount(1, $tostudent);
    }
}
