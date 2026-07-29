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
 * Tests for the no_complete_activity scheduled task user selection.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\task\no_complete_activity_task
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_complete_activity_task_test extends \advanced_testcase {
    /**
     * A user enrolled through two methods must be notified only once when the activity is not completed.
     */
    public function test_dual_enrolled_user_is_notified_once(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        // Two active enrolments (manual + self) for the same user.
        $manual = enrol_get_plugin('manual');
        $minstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->enrol_user($minstance, $student->id, $studentroleid);
        $self = enrol_get_plugin('self');
        $selfid = $self->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED, 'roleid' => $studentroleid]);
        $self->enrol_user($DB->get_record('enrol', ['id' => $selfid], '*', MUST_EXIST), $student->id, $studentroleid);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Not completed',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'no_complete_activity',
            // Expected completion date already passed so the rule is due.
            'params' => json_encode(['cmid' => $assign->cmid, 'expectedcompletiondate' => time() - 100]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Not completed alert',
                'messagebody' => 'You have not completed the activity.',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);

        $sink = $this->redirectMessages();
        ob_start();
        (new no_complete_activity_task())->execute();
        ob_end_clean();

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $tostudent = array_filter($messages, function ($m) use ($student) {
            return $m->useridto == $student->id;
        });

        $this->assertCount(1, $tostudent);
    }
}
