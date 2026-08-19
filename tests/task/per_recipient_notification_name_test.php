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
 * Proves each matched user receives the reminder with their OWN name (ticket 589127 scenario).
 *
 * Reproduces the production case: two students enrolled in the same course, a
 * no_complete_activity rule with a "Hola {$a->firstname}" notification. On the
 * current plugin version every recipient must get their own first name and
 * never another participant's, and the rule must deactivate after firing.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\task\no_complete_activity_task
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class per_recipient_notification_name_test extends \advanced_testcase {
    /**
     * Two matched students each receive their own name; no cross-delivery; rule self-deactivates.
     */
    public function test_each_matched_user_receives_own_name(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $alicia = $this->getDataGenerator()->create_user([
            'firstname' => 'Alicia',
            'lastname' => 'Ejemplo',
        ]);
        $prueba = $this->getDataGenerator()->create_user([
            'firstname' => 'Usuario',
            'lastname' => 'Prueba',
        ]);
        $teacher = $this->getDataGenerator()->create_user([
            'firstname' => 'Docente',
            'lastname' => 'Observadora',
        ]);

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($alicia->id, $course->id, $studentroleid);
        $this->getDataGenerator()->enrol_user($prueba->id, $course->id, $studentroleid);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $teacherroleid);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Finalización del curso',
            'description' => 'Ticket 589127 scenario',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'no_complete_activity',
            // Expected completion date already passed so the rule is due, as on 2026-03-26.
            'params' => json_encode(['cmid' => $assign->cmid, 'expectedcompletiondate' => time() - 100]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Recordatorio: Finaliza el curso',
                'messagebody' => 'Hola {$a-&gt;firstname}, tienes pendiente la finalización del curso.',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);

        $sink = $this->redirectMessages();
        ob_start();
        (new no_complete_activity_task())->execute();
        ob_end_clean();

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');

        // Exactly one message per matched student, none for the unconfigured teacher role.
        $this->assertCount(2, $messages);

        $byrecipient = [];
        foreach ($messages as $message) {
            $byrecipient[$message->useridto] = $message;
        }
        $this->assertArrayHasKey($alicia->id, $byrecipient);
        $this->assertArrayHasKey($prueba->id, $byrecipient);
        $this->assertArrayNotHasKey($teacher->id, $byrecipient);

        // Each recipient gets their OWN first name — never another participant's.
        $this->assertStringContainsString('Hola Alicia', $byrecipient[$alicia->id]->fullmessagehtml);
        $this->assertStringNotContainsString('Usuario', $byrecipient[$alicia->id]->fullmessagehtml);
        $this->assertStringContainsString('Hola Usuario', $byrecipient[$prueba->id]->fullmessagehtml);
        $this->assertStringNotContainsString('Alicia', $byrecipient[$prueba->id]->fullmessagehtml);

        // One-shot semantics: the rule deactivates itself after firing (shows as "Inactiva").
        $this->assertEquals(0, $DB->get_field('local_coursedynamicrules_rule', 'active', ['id' => $ruleid]));
    }
}
