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

namespace local_coursedynamicrules\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\local\fake_remote_export_client;
use local_coursedynamicrules\local\provider_dependency;
use local_coursedynamicrules\local\remote_erasure;
use local_coursedynamicrules\local\remote_export_client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fake_remote_export_client.php');

/**
 * The privacy export includes what the course service keeps about the student.
 *
 * For every approved context holding AI requests of the student, the provider asks the course
 * service (remote_export_client, a fake here) for those threads and writes the answer next to the
 * local export. When the service cannot be asked or does not answer, the export still completes,
 * with a note saying so.
 *
 * CDR-PRIV-001-R2 follow-up.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_export_test extends \core_privacy\tests\provider_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function tearDown(): void {
        provider_dependency::set_available_for_testing(null);
        parent::tearDown();
    }

    /**
     * The service's answer for some threads, with secrets and internal ids the export must not show.
     *
     * @param string[] $threadids The threads.
     * @return array
     */
    private function remote_data(array $threadids): array {
        $data = ['requests' => [], 'token_usage' => [], 'threads' => []];
        foreach ($threadids as $threadid) {
            $data['requests'][] = [
                'thread_id' => $threadid,
                'request_type' => 'ACTIVITY',
                'status' => 'COMPLETED',
                'request_config' => [
                    'lang' => 'en',
                    'license_key' => 'secret-licence',
                    'site_id' => 'site-uuid',
                    'userid' => 'pseudonym',
                    'options' => ['with_images' => false, 'api_key' => 'nested-secret'],
                ],
                'final_result' => ['name' => 'AI page'],
                'created_at' => '2026-11-10T10:00:00Z',
                'updated_at' => '2026-11-10T10:01:00Z',
            ];
            $data['token_usage'][] = [
                'thread_id' => $threadid,
                'action' => 'create_mod',
                'step' => 'Plan',
                'model_name' => 'model-x',
                'input_tokens' => 10,
                'output_tokens' => 20,
                'thoughts_token_count' => 3,
                'total_tokens' => 33,
                'cached_content_token_count' => null,
                'created_at' => '2026-11-10T10:00:30Z',
            ];
            $data['threads'][] = [
                'thread_id' => $threadid,
                'messages' => [['type' => 'human', 'content' => 'Create a page'], ['type' => 'ai', 'content' => 'Done']],
            ];
        }
        return $data;
    }

    /**
     * Use this fake as the remote export client.
     *
     * @param fake_remote_export_client $fake The fake.
     * @return fake_remote_export_client
     */
    private function use_client(fake_remote_export_client $fake): fake_remote_export_client {
        \core\di::set(remote_export_client::class, $fake);
        return $fake;
    }

    /**
     * The subcontext the remote data is written under.
     *
     * @return string[]
     */
    private function subcontext(): array {
        return [get_string('privacy:remotedata', 'local_coursedynamicrules')];
    }

    /**
     * Export the user's data in these contexts.
     *
     * @param \stdClass $user The user.
     * @param \context[] $contexts The contexts.
     * @return void
     */
    private function export(\stdClass $user, array $contexts): void {
        provider::export_user_data(new approved_contextlist(
            $user,
            'local_coursedynamicrules',
            array_map(fn(\context $context): int => (int) $context->id, $contexts)
        ));
    }

    /**
     * The service's requests, consumption and messages are written in the course context, readable.
     */
    public function test_export_includes_the_remote_data_in_the_course_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $actionid = $this->ai_action((int) $course->id);
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'thread-1');
        $fake = $this->use_client(new fake_remote_export_client($this->remote_data(['thread-1'])));

        $coursecontext = \context_course::instance($course->id);
        $this->export($student, [$coursecontext]);

        $this->assertSame([['serviceid' => 'local_coursedynamicrules', 'userid' => (int) $student->id,
            'threadids' => ['thread-1']]], $fake->calls);
        $data = writer::with_context($coursecontext)->get_data($this->subcontext());
        $this->assertNotEmpty($data, 'The data kept by the course service is missing from the export.');
        $this->assertSame([[
            'threadid' => 'thread-1',
            'type' => 'ACTIVITY',
            'status' => 'COMPLETED',
            'settings' => ['lang' => 'en', 'options' => ['with_images' => false]],
            'result' => ['name' => 'AI page'],
            'timecreated' => '2026-11-10T10:00:00Z',
            'timemodified' => '2026-11-10T10:01:00Z',
        ]], $data->requests);
        $this->assertSame([[
            'threadid' => 'thread-1',
            'operation' => 'create_mod',
            'step' => 'Plan',
            'model' => 'model-x',
            'inputtokens' => 10,
            'outputtokens' => 20,
            'reasoningtokens' => 3,
            'cachedtokens' => null,
            'totaltokens' => 33,
            'timecreated' => '2026-11-10T10:00:30Z',
        ]], $data->consumption);
        $this->assertSame([[
            'threadid' => 'thread-1',
            'messages' => [['author' => 'human', 'content' => 'Create a page'], ['author' => 'ai', 'content' => 'Done']],
        ]], $data->conversations);
        $this->assertNotEmpty($data->whatthismeans);

        $exported = json_encode($data);
        $this->assertStringNotContainsString('secret-licence', $exported);
        $this->assertStringNotContainsString('nested-secret', $exported);
        $this->assertStringNotContainsString('site-uuid', $exported);
        $this->assertStringNotContainsString('pseudonym', $exported);

        // The local export is still there, alongside.
        $this->assertNotEmpty(writer::with_context($coursecontext)->get_data(
            [get_string('privacy:export:airequests', 'local_coursedynamicrules')]
        ));
    }

    /**
     * Only the student's own threads of the approved course are sent; other students and courses stay out.
     */
    public function test_only_the_users_thread_ids_of_the_context_are_sent(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $actionid = $this->ai_action((int) $course->id);
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'mine-1');
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'mine-1');
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, null);
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'mine-2');
        remote_erasure::record_request($actionid, (int) $course->id, (int) $bystander->id, 'theirs');
        remote_erasure::record_request($this->ai_action((int) $other->id), (int) $other->id, (int) $student->id, 'other-course');
        $fake = $this->use_client(new fake_remote_export_client($this->remote_data(['mine-1', 'mine-2'])));

        $this->export($student, [\context_course::instance($course->id)]);

        $this->assertCount(1, $fake->calls);
        $this->assertSame((int) $student->id, $fake->calls[0]['userid']);
        $this->assertSame(['mine-1', 'mine-2'], $fake->calls[0]['threadids']);
    }

    /**
     * A request recorded without a thread id has nothing to ask the service for: no call, no remote data.
     */
    public function test_requests_without_thread_ids_ask_nothing(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, null);
        $fake = $this->use_client(new fake_remote_export_client($this->remote_data([])));

        $coursecontext = \context_course::instance($course->id);
        $this->export($student, [$coursecontext]);

        $this->assertSame([], $fake->calls);
        $this->assertEmpty(writer::with_context($coursecontext)->get_data($this->subcontext()));
    }

    /**
     * When the service does not answer or throws, a note is written and the export completes.
     *
     * @dataProvider failure_provider
     * @param bool $throws Whether the client throws rather than answering null.
     */
    public function test_a_failed_remote_export_writes_a_note_and_completes(bool $throws): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'thread-1');
        $fake = $this->use_client(new fake_remote_export_client(null, $throws));

        $coursecontext = \context_course::instance($course->id);
        $this->export($student, [$coursecontext]);

        $this->assertCount(1, $fake->calls);
        $data = writer::with_context($coursecontext)->get_data($this->subcontext());
        $this->assertSame(get_string('privacy:remotedataunavailable', 'local_coursedynamicrules'), $data->note);
        $this->assertObjectNotHasProperty('requests', $data);
        $this->assertNotEmpty(writer::with_context($coursecontext)->get_data(
            [get_string('privacy:export:airequests', 'local_coursedynamicrules')]
        ), 'The local export must still be written.');
        $this->assertDebuggingNotCalled();
    }

    /**
     * The two ways the remote export fails.
     *
     * @return array
     */
    public static function failure_provider(): array {
        return [
            'not retrieved' => [false],
            'thrown' => [true],
        ];
    }

    /**
     * With the plugin disabled in the provider, or the provider too old, nothing is asked and a note is written.
     *
     * @dataProvider unavailable_provider
     * @param string $how How the provider is unavailable.
     */
    public function test_an_unavailable_provider_writes_a_note_without_calling(string $how): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'thread-1');
        $fake = $this->use_client(new fake_remote_export_client($this->remote_data(['thread-1'])));
        if ($how === 'too old') {
            provider_dependency::set_available_for_testing(false);
        } else {
            if (!provider_dependency::is_available()) {
                $this->markTestSkipped('The installed provider has no plugin state API.');
            }
            \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursedynamicrules', false);
        }

        $coursecontext = \context_course::instance($course->id);
        $this->export($student, [$coursecontext]);

        $this->assertSame([], $fake->calls);
        $data = writer::with_context($coursecontext)->get_data($this->subcontext());
        $this->assertSame(get_string('privacy:remotedataunavailable', 'local_coursedynamicrules'), $data->note);
    }

    /**
     * The ways the provider can be unavailable.
     *
     * @return array
     */
    public static function unavailable_provider(): array {
        return [
            'disabled' => ['disabled'],
            'too old' => ['too old'],
        ];
    }

    /**
     * The threads of a deleted course are asked for and written in the student's own context.
     */
    public function test_threads_of_a_deleted_course_are_exported_in_the_user_context(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'orphan-1');
        // The course is gone, the rows stay (as after a course deletion whose erasure is pending).
        $DB->delete_records('course', ['id' => $course->id]);
        $fake = $this->use_client(new fake_remote_export_client($this->remote_data(['orphan-1'])));

        $usercontext = \context_user::instance($student->id);
        $this->export($student, [$usercontext]);

        $this->assertSame(['orphan-1'], $fake->calls[0]['threadids']);
        $data = writer::with_context($usercontext)->get_data($this->subcontext());
        $this->assertSame(['orphan-1'], array_column($data->requests, 'threadid'));
    }

    /**
     * A createaiactivity action in a rule of the course.
     *
     * @param int $courseid The course.
     * @return int The action id.
     */
    private function ai_action(int $courseid): int {
        global $DB;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'AI rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => 'createaiactivity',
            'actiontype' => 'createaiactivity',
            'params' => json_encode(['message' => 'Create a page', 'generateimages' => false, 'sectionnum' => 0,
                'beforemod' => null]),
        ]);
    }
}
