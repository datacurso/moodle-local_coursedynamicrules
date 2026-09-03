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

namespace local_coursedynamicrules\action\sendnotification;

use local_coursedynamicrules\core\action;

/**
 * Tests for send notification action.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\action\sendnotification\sendnotification_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sendnotification_action_test extends \advanced_testcase {
    /**
     * Insert a rule row belonging to the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * The description carries the WHOLE message body - the operator must read what will be sent.
     *
     * The old shape cut the body at 80 characters with shorten_text(), so the card showed a
     * teaser of the notification learners would actually receive. Product ask 2026-08-31:
     * everything visible, nothing cut.
     *
     * @covers ::get_description
     */
    public function test_get_description_shows_the_whole_body(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        $body = 'Hello {$a->firstname}, we noticed you have not accessed the course for a while.'
            . ' Please come back and review the pending activities of unit three before the deadline,'
            . ' and contact your tutor if you need an extension or any kind of help with the material.';
        $this->assertGreaterThan(80, \core_text::strlen($body), 'Sanity: the fixture must exceed the old cut.');

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Come back',
                'messagebody' => $body,
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ])];
        $action = new sendnotification_action($record, $course->id);

        $this->assertStringContainsString($body, $action->get_description());
    }

    /**
     * A round-trip create -> edit must persist exactly one row, update the mutated field, and leave
     * lastexecutiontime untouched. This exercises save_action() with EXPLICITLY submitted role data
     * (as an mform would after successful validation) — it does not exercise the mform's own
     * setDefault()-vs-preload_defaults() precedence for an unchecked role; that real end-to-end
     * scenario is covered separately in sendnotification_form_test.php (FIX-3/G2).
     *
     * @covers ::save_action
     */
    public function test_save_action_round_trip_persists_single_row_and_preserves_runtime_state(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'sendnotification', 'params' => json_encode([])];
        $action = new sendnotification_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Original subject',
            'messagebody' => ['text' => 'Original body', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1, $teacherroleid => 0],
            'copyrecipients' => [$studentroleid => 0, $teacherroleid => 0],
        ]);

        $id = $action->get_id();
        $DB->set_field(action::TABLE, 'lastexecutiontime', 99999, ['id' => $id]);

        // Edit: teacher stays unchecked (never submitted as 1), subject changes.
        $stored = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editaction = new sendnotification_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Updated subject',
            'messagebody' => ['text' => 'Updated body', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1, $teacherroleid => 0],
            'copyrecipients' => [$studentroleid => 0, $teacherroleid => 0],
        ]);

        $this->assertEquals($id, $editaction->get_id());
        $this->assertEquals(1, $DB->count_records(action::TABLE, ['ruleid' => $ruleid]));

        $final = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(99999, $final->lastexecutiontime);
        $this->assertEquals($ruleid, $final->ruleid);

        $params = json_decode($final->params);
        $this->assertEquals('Updated subject', $params->messagesubject);
        $this->assertEquals([$studentroleid], $params->primaryroleids);
        $this->assertEquals([], $params->copyroleids);
    }

    /**
     * A no-op edit (preload_defaults() feeds the previously stored body back into the editor, and
     * the user re-saves without changing anything) must leave the stored body byte-identical.
     * save_action() used to run format_text() at save time and store its OUTPUT, so re-saving the
     * already-formatted text re-filtered it again, breaking `{$a->...}` placeholders (G6).
     *
     * @covers ::save_action
     */
    public function test_save_action_round_trip_preserves_message_body_byte_identical(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        $body = 'Hello {$a->fullname}, please visit {$a->courselink}.';

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'sendnotification', 'params' => json_encode([])];
        $action = new sendnotification_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subj',
            'messagebody' => ['text' => $body, 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $id = $action->get_id();
        $firstsaved = json_decode($DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST)->params)->messagebody;

        // Edit without changing content: preload_defaults() feeds $firstsaved back into the editor
        // as its default text, and the user re-submits unchanged.
        $stored = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editaction = new sendnotification_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subj',
            'messagebody' => ['text' => $firstsaved, 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $secondsaved = json_decode($DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST)->params)->messagebody;

        $this->assertSame($firstsaved, $secondsaved);
    }

    /**
     * FIX3-4: the stored body is purified with clean_text() at save time - re-opening this action's
     * own edit form re-materialises the stored value inside the WYSIWYG unescaped, so an unpurified
     * payload is an editor XSS / privilege-escalation sink. This is intentionally NOT byte-identical
     * to what the user typed (a stray '>' and the '->' inside a placeholder are both HTML-encoded),
     * but the placeholder token itself must survive cleaning so replace_placeholders() - which
     * matches the ENCODED form - keeps working.
     *
     * @covers ::save_action
     */
    public function test_save_action_purifies_body_and_placeholders_survive_cleaning(): void {
        global $DB;

        $this->resetAfterTest(true);
        // clean_text(FORMAT_HTML) only runs the HTML Purifier's stricter encoding when this is
        // enabled - pin it explicitly so the expected encoded output does not depend on whatever
        // the test environment's ambient config happens to be (FIX4).
        set_config('enablehtmlpurifier', 1);

        $course = $this->getDataGenerator()->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        $rawbody = 'Hello {$a->fullname}, this is a plain message with a stray > character.';

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'sendnotification', 'params' => json_encode([])];
        $action = new sendnotification_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subj',
            'messagebody' => ['text' => $rawbody, 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $stored = json_decode($DB->get_record(action::TABLE, ['id' => $action->get_id()], '*', MUST_EXIST)->params);

        $this->assertSame(
            'Hello {$a-&gt;fullname}, this is a plain message with a stray &gt; character.',
            $stored->messagebody
        );
        $this->assertStringContainsString('{$a-&gt;fullname}', $stored->messagebody);
    }

    /**
     * FIX3-4: a <script> payload submitted through the editor must be stripped at SAVE time (not
     * only at send/execute() time) - the stored-XSS risk exists as soon as an admin re-opens this
     * action's own edit form, independent of whether the rule ever executes.
     *
     * @covers ::save_action
     */
    public function test_save_action_strips_script_payload_at_save_time(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'sendnotification', 'params' => json_encode([])];
        $action = new sendnotification_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subj',
            'messagebody' => ['text' => '<script>alert(document.cookie)</script>Hello.', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $stored = json_decode($DB->get_record(action::TABLE, ['id' => $action->get_id()], '*', MUST_EXIST)->params);

        $this->assertStringNotContainsString('<script>', $stored->messagebody);
        $this->assertStringContainsString('Hello.', $stored->messagebody);
    }

    /**
     * FIX3-5: editing a LEGACY row (no 'bodyisraw' marker at all, as every pre-FIX2-6 row is) must
     * NOT stamp 'bodyisraw' => true on save - doing so would make get_formatted_messagebody() run
     * format_text() on an ALREADY-formatted body a second time at send, corrupting it. The edited
     * row must stay unmarked, keeping the verbatim send path.
     *
     * @covers ::save_action
     */
    public function test_save_action_editing_legacy_row_keeps_it_unmarked(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        // A legacy row: no 'bodyisraw' key at all, as every pre-FIX2-6 row is.
        $record = (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Legacy subject',
                'messagebody' => 'Legacy already-formatted body.',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ];
        $record->id = $DB->insert_record(action::TABLE, $record);

        $stored = $DB->get_record(action::TABLE, ['id' => $record->id], '*', MUST_EXIST);
        $editaction = new sendnotification_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Legacy subject edited',
            'messagebody' => ['text' => 'Legacy already-formatted body, edited.', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $final = json_decode($DB->get_field(action::TABLE, 'params', ['id' => $record->id]));
        $this->assertFalse(property_exists($final, 'bodyisraw'));
    }

    /**
     * FIX3-5: editing a row that WAS already raw (bodyisraw => true) must keep the marker set on
     * update too.
     *
     * @covers ::save_action
     */
    public function test_save_action_editing_raw_row_keeps_bodyisraw_marker(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'sendnotification', 'params' => json_encode([])];
        $action = new sendnotification_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subj',
            'messagebody' => ['text' => 'Body', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $id = $action->get_id();
        $stored = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editaction = new sendnotification_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subj edited',
            'messagebody' => ['text' => 'Body edited', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);

        $final = json_decode($DB->get_field(action::TABLE, 'params', ['id' => $id]));
        $this->assertTrue($final->bodyisraw);
    }

    /**
     * Test action is skipped when matched user is not in observed roles.
     *
     * @covers ::execute
     */
    public function test_execute_does_not_send_when_user_role_is_not_observed(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course(['fullname' => 'Course placeholder test']);
        $teacher = $generator->create_user([
            'firstname' => 'TeacherFirst',
            'lastname' => 'TeacherLast',
        ]);
        $student = $generator->create_user([
            'firstname' => 'StudentFirst',
            'lastname' => 'StudentLast',
        ]);

        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $generator->enrol_user($teacher->id, $course->id, $teacherroleid);
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Nactivity notification',
                'messagebody' => '{$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}',
                'primaryroleids' => [$teacherroleid],
                'copyroleids' => [$studentroleid],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $rulecontext = (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
        ];

        $result = $action->execute($rulecontext);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');

        $this->assertFalse($result);
        $this->assertCount(0, $messages);
    }

    /**
     * Test observer recipients receive an observation-formatted notification.
     *
     * @covers ::execute
     */
    public function test_execute_sends_observation_message_to_other_role(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course(['fullname' => 'Course placeholder test']);
        $teacher = $generator->create_user([
            'firstname' => 'TeacherFirst',
            'lastname' => 'TeacherLast',
        ]);
        $student = $generator->create_user([
            'firstname' => 'StudentFirst',
            'lastname' => 'StudentLast',
        ]);

        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $generator->enrol_user($teacher->id, $course->id, $teacherroleid);
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Nactivity notification',
                'messagebody' => '{$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [$teacherroleid],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $rulecontext = (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
        ];

        $action->execute($rulecontext);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');

        $this->assertCount(2, $messages);

        $messagesbyrecipient = [];
        foreach ($messages as $message) {
            $messagesbyrecipient[$message->useridto] = $message;
        }

        $message = $messagesbyrecipient[$teacher->id];
        $observermsubject = get_string('observer_notification_subject', 'local_coursedynamicrules', (object) [
            'fullname' => fullname($student),
            'subject' => 'Nactivity notification',
        ]);
        $observerintro = get_string('observer_notification_intro', 'local_coursedynamicrules', fullname($student));

        $this->assertEquals($teacher->id, $message->useridto);
        $this->assertEquals($observermsubject, $message->subject);
        $this->assertStringContainsString($observerintro, $message->fullmessagehtml);
        $this->assertStringContainsString(fullname($student), $message->fullmessagehtml);
        $this->assertStringNotContainsString(fullname($teacher), $message->fullmessagehtml);
    }

    /**
     * A copy-only configuration (no primary role configured) must notify the copy roles about the
     * matched user's condition without ever messaging that user directly.
     *
     * @covers ::execute
     */
    public function test_execute_sends_copy_only_when_no_primary_role_configured(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course(['fullname' => 'Course placeholder test']);
        $teacher = $generator->create_user([
            'firstname' => 'TeacherFirst',
            'lastname' => 'TeacherLast',
        ]);
        $student = $generator->create_user([
            'firstname' => 'StudentFirst',
            'lastname' => 'StudentLast',
        ]);

        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $generator->enrol_user($teacher->id, $course->id, $teacherroleid);
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Nactivity notification',
                'messagebody' => '{$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}',
                'primaryroleids' => [],
                'copyroleids' => [$teacherroleid],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $rulecontext = (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
        ];

        $result = $action->execute($rulecontext);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');

        $this->assertNotFalse($result);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals($teacher->id, $message->useridto);
        $this->assertNotEquals($student->id, $message->useridto);
    }

    /**
     * When neither primary nor copy roles are configured, execute() must bail out before touching
     * the database, instead of resolving the user/course records for nothing.
     *
     * @covers ::execute
     */
    public function test_execute_returns_false_when_no_roles_configured_at_all(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course(['fullname' => 'Course placeholder test']);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Nactivity notification',
                'messagebody' => 'Body',
                'primaryroleids' => [],
                'copyroleids' => [],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $rulecontext = (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
        ];

        $result = $action->execute($rulecontext);

        $this->assertFalse($result);
        $this->assertCount(0, $sink->get_messages_by_component('local_coursedynamicrules'));
    }

    /**
     * Test matched user and observer recipients get different message formats.
     *
     * @covers ::execute
     */
    public function test_execute_sends_different_messages_for_target_and_observer_roles(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course(['fullname' => 'Course placeholder test']);
        $teacher = $generator->create_user([
            'firstname' => 'TeacherFirst',
            'lastname' => 'TeacherLast',
        ]);
        $student = $generator->create_user([
            'firstname' => 'StudentFirst',
            'lastname' => 'StudentLast',
        ]);

        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $generator->enrol_user($teacher->id, $course->id, $teacherroleid);
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Nactivity notification',
                'messagebody' => '{$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [$teacherroleid],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $rulecontext = (object) [
            'courseid' => $course->id,
            'userid' => $student->id,
        ];

        $action->execute($rulecontext);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');

        $this->assertCount(2, $messages);

        $messagesbyrecipient = [];
        foreach ($messages as $message) {
            $messagesbyrecipient[$message->useridto] = $message;
        }

        $this->assertArrayHasKey($student->id, $messagesbyrecipient);
        $this->assertArrayHasKey($teacher->id, $messagesbyrecipient);

        $observermsubject = get_string('observer_notification_subject', 'local_coursedynamicrules', (object) [
            'fullname' => fullname($student),
            'subject' => 'Nactivity notification',
        ]);
        $observerintro = get_string('observer_notification_intro', 'local_coursedynamicrules', fullname($student));

        $studentmessage = $messagesbyrecipient[$student->id];
        $teachermessage = $messagesbyrecipient[$teacher->id];

        $this->assertEquals('Nactivity notification', $studentmessage->subject);
        $this->assertStringNotContainsString($observerintro, $studentmessage->fullmessagehtml);
        $this->assertStringContainsString(fullname($student), $studentmessage->fullmessagehtml);
        $this->assertStringNotContainsString(fullname($teacher), $studentmessage->fullmessagehtml);

        $this->assertEquals($observermsubject, $teachermessage->subject);
        $this->assertStringContainsString($observerintro, $teachermessage->fullmessagehtml);
        $this->assertStringContainsString(fullname($student), $teachermessage->fullmessagehtml);
        $this->assertStringNotContainsString(fullname($teacher), $teachermessage->fullmessagehtml);
    }

    /**
     * FIX2-5: format_text() must be computed ONCE per rule execution, not once per matched user.
     * rule::execute_actions() calls execute() on the SAME action instance for every matched user in
     * a single rule run, so the formatted body must be memoised on the instance. Mutating the
     * in-memory params AFTER the first execute() call simulates what a per-call recompute would
     * read: if execute() recomputed instead of reusing the cached value, the SECOND matched user
     * would receive the mutated text instead of what was formatted once at the start of the run.
     *
     * @covers ::execute
     */
    public function test_execute_formats_message_body_once_per_rule_run_not_per_matched_user(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $studentone = $generator->create_user();
        $studenttwo = $generator->create_user();
        $generator->enrol_user($studentone->id, $course->id, $studentroleid);
        $generator->enrol_user($studenttwo->id, $course->id, $studentroleid);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Subject',
                'messagebody' => 'Hello student.',
                'bodyisraw' => true,
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);

        // First matched user: this computes and caches the formatted body.
        $action->execute((object) ['courseid' => $course->id, 'userid' => $studentone->id]);

        // Mutate the in-memory params directly, as a per-call recompute would observe.
        $paramsproperty = new \ReflectionProperty(\local_coursedynamicrules\core\action::class, 'params');
        $paramsproperty->setAccessible(true);
        $mutatedparams = $paramsproperty->getValue($action);
        $mutatedparams->messagebody = 'MUTATED - should never be sent.';
        $paramsproperty->setValue($action, $mutatedparams);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $studenttwo->id]);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $this->assertCount(2, $messages);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('MUTATED', $message->fullmessagehtml);
            $this->assertStringContainsString('Hello student.', $message->fullmessagehtml);
        }
    }

    /**
     * FIX2-6: a row saved WITH the 'bodyisraw' marker must have format_text() applied at send time
     * (proven here via clean_text()'s tag stripping, which format_text() runs by default).
     *
     * @covers ::execute
     */
    public function test_execute_formats_raw_row_at_send_time(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Subject',
                'messagebody' => '<script>alert(1)</script>Hello.',
                'bodyisraw' => true,
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $student->id]);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('<script>', reset($messages)->fullmessagehtml);
        $this->assertStringContainsString('Hello.', reset($messages)->fullmessagehtml);
    }

    /**
     * FIX2-6: a LEGACY row (no 'bodyisraw' marker, as every pre-FIX2-6 row is) must be sent
     * verbatim - no format_text() pass applied, since an older version already formatted it at save
     * time. Proven via a `<script>` payload that format_text()'s default clean_text() would strip.
     *
     * @covers ::execute
     */
    public function test_execute_sends_legacy_row_without_marker_verbatim(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $sink = $this->redirectMessages();

        $course = $generator->create_course();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        $body = '<script>alert(1)</script>Hello.';
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Subject',
                'messagebody' => $body,
                // Deliberately no 'bodyisraw' key: simulates a row saved before FIX2-6.
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $student->id]);

        $messages = $sink->get_messages_by_component('local_coursedynamicrules');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString($body, reset($messages)->fullmessagehtml);
    }

    /**
     * Test description shows recipient roles, copy roles and a shortened plain text body.
     *
     * @covers ::get_description
     */
    public function test_get_description_shows_roles_and_body(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $bodytail = 'and this final sentence keeps going well past the eighty character limit for sure';
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Grade alert',
                'messagebody' => '<p>Hello <strong>student</strong>, your grade is low ' . $bodytail . '</p>',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [$teacherroleid],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $description = $action->get_description();

        $rolenames = role_get_names(\context_course::instance($course->id), ROLENAME_ALIAS, true);

        $this->assertStringContainsString('Grade alert', $description);
        $this->assertStringContainsString($rolenames[$studentroleid], $description);
        $this->assertStringContainsString($rolenames[$teacherroleid], $description);
        $this->assertStringContainsString('your grade is low', $description);
        $this->assertStringNotContainsString('<p>', $description);
        $this->assertStringNotContainsString('<strong>', $description);
        // Contract change (product ask 2026-08-31): the WHOLE body shows, nothing is cut at 80.
        $this->assertStringContainsString($bodytail, $description);
    }

    /**
     * Test description resolves legacy role param keys and shows "none" when a role list is empty.
     *
     * @covers ::get_description
     */
    public function test_get_description_uses_legacy_role_keys_and_none(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Legacy subject',
                'messagebody' => 'Legacy body',
                'observedroleids' => [$studentroleid],
            ]),
        ];

        $action = new sendnotification_action($record, $course->id);
        $description = $action->get_description();

        $rolenames = role_get_names(\context_course::instance($course->id), ROLENAME_ALIAS, true);

        $this->assertStringContainsString($rolenames[$studentroleid], $description);
        $this->assertStringContainsString(get_string('none'), $description);
    }
}
