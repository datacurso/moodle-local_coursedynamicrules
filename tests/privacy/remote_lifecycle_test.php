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

use core_availability\tree;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\action\createaiactivity\testable_createaiactivity_action;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\local\aiactivity_key;
use local_coursedynamicrules\local\fake_remote_erasure_client;
use local_coursedynamicrules\local\remote_erasure;
use local_coursedynamicrules\local\remote_erasure_client;
use local_coursedynamicrules\task\remote_erasure_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/testable_createaiactivity_action.php');
require_once(__DIR__ . '/../fixtures/fake_remote_erasure_client.php');
require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');

/**
 * What the course service keeps about a student, and how a privacy request reaches it.
 *
 * Every AI activity starts with /activity/init, and the service keeps that thread (the instructions,
 * the request configuration and the generated activity) on its side. The plugin records each thread
 * against its student, so the privacy provider can list it, export it and ask the service to erase
 * it. The call goes through remote_erasure_client (POST /privacy/erase on the course service in
 * production), a fake here, and a failed or unavailable call keeps the rows marked as pending erasure, which the
 * scheduled remote_erasure_task sends again on every run until the service confirms.
 *
 * CDR-PRIV-001-R2.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\remote_erasure
 * @covers     \local_coursedynamicrules\privacy\provider
 * @covers     \local_coursedynamicrules\task\remote_erasure_task
 * @covers     \local_coursedynamicrules\observer\user_deleted
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_lifecycle_test extends \core_privacy\tests\provider_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function tearDown(): void {
        testable_createaiactivity_action::reset();
        parent::tearDown();
    }

    /**
     * The thread /activity/init returns is recorded against the student, with the module it became.
     *
     * @return void
     */
    public function test_init_records_the_thread_for_the_student(): void {
        global $DB;
        foreach (['aiprovider_datacurso', 'local_coursegen'] as $component) {
            if (!\core_plugin_manager::instance()->get_plugin_info($component)) {
                $this->markTestSkipped($component . ' is not installed; the AI activity action requires it.');
            }
        }
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $actionid = $this->ai_action((int) $course->id);

        $client = $this->getMockBuilder(\aiprovider_datacurso\httpclient\ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();
        $client->method('request')->willReturn(['thread_id' => 'thread-77', 'status' => 'pending']);
        $client->method('get_base_url')->willReturn('https://ai.example.test/api/v1/');
        testable_createaiactivity_action::$client = $client;
        testable_createaiactivity_action::$streamevent = ['type' => 'completed', 'result' => $this->page_result()];

        $record = $DB->get_record('local_coursedynamicrules_action', ['id' => $actionid], '*', MUST_EXIST);
        $action = new testable_createaiactivity_action($record, (int) $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => (int) $student->id]);

        $rows = array_values($DB->get_records(remote_erasure::TABLE));
        $this->assertCount(1, $rows, 'The thread the service keeps for the student was not recorded.');
        $this->assertSame('thread-77', $rows[0]->threadid);
        $this->assertSame($actionid, (int) $rows[0]->actionid);
        $this->assertSame((int) $course->id, (int) $rows[0]->courseid);
        $this->assertSame((int) $student->id, (int) $rows[0]->userid);
        $page = $DB->get_record('page', ['course' => $course->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $this->assertSame((int) $cm->id, (int) $rows[0]->cmid, 'The generated module is not linked to its thread.');
    }

    /**
     * A user with a thread on the service is in the contextlist, through the course of the request.
     *
     * @return void
     */
    public function test_user_with_remote_data_is_in_contextlist(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'thread-1');

        $coursecontext = \context_course::instance($course->id);
        $this->assertContains(
            (int) $coursecontext->id,
            array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids())
        );
        $this->assertSame([], provider::get_contexts_for_userid((int) $bystander->id)->get_contextids());

        $userlist = new \core_privacy\local\request\userlist($coursecontext, 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $student->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * The export lists every request the service keeps for the user: thread, rule, activity, time.
     *
     * @return void
     */
    public function test_export_lists_the_remote_requests(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $actionid = $this->ai_action((int) $course->id);
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'thread-1');
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'thread-2');

        $coursecontext = \context_course::instance($course->id);
        provider::export_user_data(new approved_contextlist($student, 'local_coursedynamicrules', [$coursecontext->id]));

        $data = writer::with_context($coursecontext)->get_data(
            [get_string('privacy:export:airequests', 'local_coursedynamicrules')]
        );
        $this->assertNotEmpty($data, 'The requests kept by the course service are missing from the export.');
        $this->assertCount(2, $data->requests);
        $this->assertSame(['thread-1', 'thread-2'], array_column($data->requests, 'threadid'));
        $this->assertSame('AI rule', $data->requests[0]['rule']);
        $this->assertNotEmpty($data->requests[0]['timecreated']);
        $this->assertNotEmpty($data->whatthismeans);
    }

    /**
     * The erasure asks the service to erase this plugin's threads of the user, then forgets them.
     *
     * @return void
     */
    public function test_erasure_calls_the_service_scoped_to_this_plugin_and_forgets_rows(): void {
        global $DB;
        $fake = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $fake);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $actionid = $this->ai_action((int) $course->id);
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'thread-a1');
        remote_erasure::record_request($actionid, (int) $course->id, (int) $student->id, 'thread-a2');
        remote_erasure::record_request($actionid, (int) $course->id, (int) $bystander->id, 'thread-b1');

        $contextids = provider::get_contexts_for_userid((int) $student->id)->get_contextids();
        provider::delete_data_for_user(new approved_contextlist($student, 'local_coursedynamicrules', $contextids));

        $this->assertSame(
            [[
                'serviceid' => 'local_coursedynamicrules',
                'userid' => (int) $student->id,
                'scope' => remote_erasure_client::SCOPE_USER,
                'threadids' => ['thread-a1', 'thread-a2'],
            ]],
            $fake->calls
        );
        $this->assertSame(0, $DB->count_records(remote_erasure::TABLE, ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records(remote_erasure::TABLE, ['userid' => $bystander->id]));
        $this->assertSame(
            0,
            $DB->count_records_select(remote_erasure::TABLE, 'erasurerequested IS NOT NULL'),
            'The bystander, whom nobody asked to erase, was marked for erasure.'
        );
    }

    /**
     * When the service does not confirm the erasure, the rows stay, marked as pending erasure.
     *
     * The mark is what the scheduled task works from, so the request outlives any task.
     *
     * @return void
     */
    public function test_failed_remote_erasure_keeps_rows_pending_for_the_scheduled_task(): void {
        global $DB;
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(false));
        $this->mock_clock_with_frozen(1767225600);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'thread-1');

        $contextids = provider::get_contexts_for_userid((int) $student->id)->get_contextids();
        provider::delete_data_for_user(new approved_contextlist($student, 'local_coursedynamicrules', $contextids));

        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows, 'A request the service did not confirm erasing was forgotten.');
        $this->assertSame(1767225600, (int) $rows[0]->erasurerequested, 'Nothing records that erasure was requested.');
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(remote_erasure_task::class));
    }

    /**
     * Deleting an account marks the user's requests pending; the remote call is left to the task.
     *
     * @return void
     */
    public function test_user_deleted_marks_requests_pending_without_calling_the_service(): void {
        $fake = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $fake);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'thread-1');

        delete_user($student);

        $this->assertSame([], $fake->calls, 'The account deletion called the service inside the deletion.');
        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->erasurerequested, 'Deleting the account did not request the erasure of its threads.');
    }

    /**
     * The scheduled task sends the pending erasure and forgets the rows once the service confirms.
     *
     * @return void
     */
    public function test_scheduled_task_erases_pending_requests_once_the_service_confirms(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        remote_erasure::record_request($this->ai_action((int) $course->id), (int) $course->id, (int) $student->id, 'thread-1');
        delete_user($student);

        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(false));
        $this->run_scheduled_task();
        $this->assertSame(1, $DB->count_records(remote_erasure::TABLE, ['userid' => $student->id]));

        $fake = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $fake);
        $this->run_scheduled_task();
        $this->assertSame(
            [['serviceid' => 'local_coursedynamicrules', 'userid' => (int) $student->id,
                'scope' => remote_erasure_client::SCOPE_USER, 'threadids' => ['thread-1']]],
            $fake->calls
        );
        $this->assertSame(0, $DB->count_records(remote_erasure::TABLE, ['userid' => $student->id]));
    }

    /**
     * Without a configured AI provider the production client reports the erasure as not done.
     *
     * @return void
     */
    public function test_the_production_client_without_a_provider_reports_not_done(): void {
        $this->assertFalse(
            \core\di::get(remote_erasure_client::class)->erase(
                'local_coursedynamicrules',
                2,
                remote_erasure_client::SCOPE_THREADS,
                ['thread-1']
            )
        );
    }

    /**
     * The upgrade records, without a thread id, the students the existing AI activities were generated for.
     *
     * @return void
     */
    public function test_upgrade_backfills_requests_from_keyed_activities(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $actionid = $this->ai_action((int) $course->id);
        $generated = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'idnumber' => aiactivity_key::for_action_user($actionid, (int) $student->id),
        ]);
        $node = enableactivity_action::mark_node((object) ['type' => 'user', 'userids' => [(int) $student->id]], $actionid);
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json([$node], tree::OP_AND, false)),
            ['id' => $generated->cmid]
        );

        local_coursedynamicrules_upgrade_backfill_ai_requests();
        local_coursedynamicrules_upgrade_backfill_ai_requests();

        $rows = array_values($DB->get_records(remote_erasure::TABLE));
        $this->assertCount(1, $rows, 'The backfill must record each generated activity once, and be safe to repeat.');
        $this->assertNull($rows[0]->threadid);
        $this->assertSame((int) $student->id, (int) $rows[0]->userid);
        $this->assertSame((int) $generated->cmid, (int) $rows[0]->cmid);
        $this->assertSame($actionid, (int) $rows[0]->actionid);
    }

    /**
     * Run the scheduled remote erasure task once, discarding what it prints.
     *
     * @return void
     */
    private function run_scheduled_task(): void {
        ob_start();
        try {
            (new remote_erasure_task())->execute();
        } finally {
            ob_end_clean();
        }
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

    /**
     * A flat page result, as the service returns it.
     *
     * @return array
     */
    private function page_result(): array {
        return [
            'action' => 'create',
            'resource_type' => 'page',
            'parameters' => [
                'modulename' => 'page',
                'name' => 'AI page',
                'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'page' => ['text' => '<p>Content</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'display' => 5,
                'printintro' => 0,
                'printlastmodified' => 1,
                'visible' => 1,
                'cmidnumber' => '',
            ],
        ];
    }
}
