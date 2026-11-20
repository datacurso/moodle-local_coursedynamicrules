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
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\event\remote_erasure_failed;
use local_coursedynamicrules\local\fake_remote_erasure_client;
use local_coursedynamicrules\local\fake_remote_export_client;
use local_coursedynamicrules\local\remote_erasure;
use local_coursedynamicrules\local\remote_erasure_client;
use local_coursedynamicrules\local\remote_export_client;
use local_coursedynamicrules\task\remote_erasure_task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fake_remote_erasure_client.php');
require_once(__DIR__ . '/../fixtures/fake_remote_export_client.php');

/**
 * A requested remote erasure is durable: it is recorded on the rows and sent until the service confirms.
 *
 * The course service may not confirm an erasure for a long time (unreachable, a site without token
 * balance or subscription). An adhoc task gives up after core's attempt limit; the request
 * is therefore recorded in local_coursedynamicrules_aireq.erasurerequested and the scheduled
 * remote_erasure_task sends it again on every run, whatever happened to any task before.
 *
 * CDR-PRIV-001-R2.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\remote_erasure
 * @covers     \local_coursedynamicrules\local\course_service_remote_erasure_client
 * @covers     \local_coursedynamicrules\privacy\provider
 * @covers     \local_coursedynamicrules\task\remote_erasure_task
 * @covers     \local_coursedynamicrules\observer\course_deleted
 * @covers     \local_coursedynamicrules\event\remote_erasure_failed
 * @covers     \local_coursedynamicrules\admin\remote_erasure_pending_setting
 * @covers     \local_coursedynamicrules\admin\remote_erasure_days_setting
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending_remote_erasure_test extends \core_privacy\tests\provider_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Weeks of failed runs - far beyond the 12 attempts core gives an adhoc task - lose nothing.
     *
     * @return void
     */
    public function test_pending_erasure_outlives_the_adhoc_retry_limit_and_is_sent_once_the_service_answers(): void {
        global $DB;
        $clock = $this->mock_clock_with_frozen(1767225600);
        $failing = new fake_remote_erasure_client(false);
        \core\di::set(remote_erasure_client::class, $failing);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');
        $coursecontext = \context_course::instance($course->id);
        provider::delete_data_for_user(new approved_contextlist($student, 'local_coursedynamicrules', [$coursecontext->id]));

        // One run a day for five weeks: past the adhoc limit and past core's purge of failed adhoc tasks.
        for ($run = 0; $run < 35; $run++) {
            $clock->bump(DAYSECS);
            $this->run_scheduled_task();
        }
        $this->assertCount(1 + 35, $failing->calls, 'The scheduled task stopped asking the service.');
        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows, 'A pending erasure was forgotten while the service never confirmed it.');
        $this->assertSame(1767225600, (int) $rows[0]->erasurerequested);

        $answering = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $answering);
        $this->use_export($this->nothing_left());
        $this->run_scheduled_task();
        $this->run_scheduled_task();

        $this->assertCount(1, $answering->calls, 'A confirmed erasure was sent again.');
        $this->assertSame(['thread-1'], $answering->calls[0]['threadids']);
        $this->assertSame(0, $DB->count_records(remote_erasure::TABLE));
    }

    /**
     * Requests nobody asked to erase are never sent and never touched.
     *
     * @return void
     */
    public function test_scheduled_task_leaves_requests_nobody_asked_to_erase_alone(): void {
        global $DB;
        $fake = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $fake);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');

        $this->run_scheduled_task();

        $this->assertSame([], $fake->calls);
        $this->assertSame(1, $DB->count_records(remote_erasure::TABLE));
    }

    /**
     * A client that throws leaves the request pending instead of breaking the privacy request.
     *
     * @return void
     */
    public function test_a_client_failure_keeps_the_request_pending(): void {
        $client = $this->createMock(remote_erasure_client::class);
        $client->method('erase')->willThrowException(new \moodle_exception('error'));
        \core\di::set(remote_erasure_client::class, $client);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');

        $this->assertFalse(remote_erasure::erase((int) $student->id, (int) $course->id));
        $this->assertDebuggingCalled();

        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->erasurerequested);
    }

    /**
     * Erasing one course sends exactly that course's threads, and nothing that reaches another course.
     *
     * A row recorded by the upgrade has no thread id. The service never received the course, so the
     * only way to reach such a row is by user, which would erase the student's other courses too.
     * It stays pending until every request of the student is to be erased, and is then covered by
     * the user-wide request.
     *
     * @return void
     */
    public function test_course_scoped_erasure_sends_only_that_courses_threads(): void {
        global $DB;
        $fake = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $fake);
        $this->use_export($this->nothing_left());
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $coursea->id, (int) $student->id, 'thread-a');
        $unthreaded = remote_erasure::record_request(9001, (int) $coursea->id, (int) $student->id, null);
        remote_erasure::record_request(9002, (int) $courseb->id, (int) $student->id, 'thread-b');

        $contexta = \context_course::instance($coursea->id);
        provider::delete_data_for_users(
            new approved_userlist($contexta, 'local_coursedynamicrules', [(int) $student->id])
        );

        $this->assertSame(
            [['serviceid' => 'local_coursedynamicrules', 'userid' => (int) $student->id,
                'scope' => remote_erasure_client::SCOPE_THREADS, 'threadids' => ['thread-a'], 'clientrequestids' => []]],
            $fake->calls
        );
        $left = $DB->get_records(remote_erasure::TABLE, null, 'id ASC');
        $this->assertSame([$unthreaded], array_map('intval', array_keys(array_filter(
            $left,
            fn($row) => $row->erasurerequested !== null
        ))), 'The unthreaded row of course A must stay pending, and nothing else.');
        $this->assertCount(2, $left);

        // Once course B is erased too, nothing of the student is left to keep: one user-wide request.
        $fake->calls = [];
        provider::delete_data_for_users(new approved_userlist(
            \context_course::instance($courseb->id),
            'local_coursedynamicrules',
            [(int) $student->id]
        ));
        $this->assertSame(
            [['serviceid' => 'local_coursedynamicrules', 'userid' => (int) $student->id,
                'scope' => remote_erasure_client::SCOPE_USER, 'threadids' => ['thread-b'], 'clientrequestids' => []]],
            $fake->calls
        );
        $this->assertSame(0, $DB->count_records(remote_erasure::TABLE));
    }

    /**
     * A course whose requests have no thread id sends nothing while the student has other courses.
     *
     * @return void
     */
    public function test_course_scoped_erasure_never_sends_an_empty_thread_list(): void {
        $fake = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $fake);
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $coursea->id, (int) $student->id, null);
        remote_erasure::record_request(9002, (int) $courseb->id, (int) $student->id, 'thread-b');

        $this->assertFalse(remote_erasure::erase((int) $student->id, (int) $coursea->id));
        $this->run_scheduled_task();

        $this->assertSame([], $fake->calls, 'A course-scoped request went out with no thread to scope it.');
        $this->assertCount(2, remote_erasure::requests_of((int) $student->id));
    }

    /**
     * Deleting a course marks its requests pending, and they stay reachable through the user context.
     *
     * @return void
     */
    public function test_course_deletion_marks_its_requests_pending_and_keeps_them_reachable(): void {
        global $DB;
        $fake = new fake_remote_erasure_client(false);
        \core\di::set(remote_erasure_client::class, $fake);
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $coursea->id, (int) $student->id, 'thread-a');
        remote_erasure::record_request(9002, (int) $courseb->id, (int) $student->id, 'thread-b');

        ob_start();
        delete_course($coursea, false);
        ob_end_clean();

        $rows = $DB->get_records(remote_erasure::TABLE, ['courseid' => $coursea->id]);
        $this->assertCount(1, $rows);
        $this->assertNotNull(reset($rows)->erasurerequested, 'Deleting the course requested no erasure of its threads.');
        $this->assertNull($DB->get_field(remote_erasure::TABLE, 'erasurerequested', ['courseid' => $courseb->id]));

        // The course context is gone; the student's own context carries the request instead.
        $usercontext = \context_user::instance($student->id);
        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        $this->assertContains((int) $usercontext->id, $contextids, 'The deleted course\'s request is unreachable.');
        $this->assertNotContains(
            (int) \context_user::instance($bystander->id)->id,
            array_map('intval', provider::get_contexts_for_userid((int) $bystander->id)->get_contextids())
        );

        $userlist = new userlist($usercontext, 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $student->id], array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($student, 'local_coursedynamicrules', [$usercontext->id]));
        $data = writer::with_context($usercontext)->get_data(
            [get_string('privacy:export:airequests', 'local_coursedynamicrules')]
        );
        $this->assertNotEmpty($data, 'The deleted course\'s request is missing from the export.');
        $this->assertSame(['thread-a'], array_column($data->requests, 'threadid'));
        $this->assertSame((int) $coursea->id, $data->requests[0]['course']);
        $this->assertNotEmpty($data->requests[0]['erasurerequested']);

        // The scheduled task sends only the deleted course's thread: course B is still the student's.
        $this->run_scheduled_task();
        $this->assertSame(
            [['serviceid' => 'local_coursedynamicrules', 'userid' => (int) $student->id,
                'scope' => remote_erasure_client::SCOPE_THREADS, 'threadids' => ['thread-a'], 'clientrequestids' => []]],
            $fake->calls
        );
    }

    /**
     * With the production client and no configured provider nothing is erased: the rows stay, still pending.
     *
     * @return void
     */
    public function test_production_client_never_lets_a_request_be_forgotten(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');

        $this->assertFalse(remote_erasure::erase((int) $student->id));
        $this->run_scheduled_task();

        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->erasurerequested);
    }

    /**
     * The service says "erased" but its export still returns the thread: the seal stays, a failure is counted.
     *
     * CDR-PRIV-001-R2. An "erased" answer is not taken on trust: the same threads and keys are exported
     * right after, and anything still returned keeps the rows pending.
     *
     * @return void
     */
    public function test_a_confirmed_erasure_the_export_contradicts_keeps_the_seal_and_counts_a_failure(): void {
        $this->mock_clock_with_frozen(1767225600);
        $erasure = new fake_remote_erasure_client(true);
        \core\di::set(remote_erasure_client::class, $erasure);
        $export = $this->use_export([
            'requests' => [['thread_id' => 'thread-1']],
            'token_usage' => [],
            'threads' => [['thread_id' => 'thread-1']],
        ]);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $key = '0b5f1d6e-8c2a-4f3b-9d7e-1a2b3c4d5e6f';
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1', null, $key);

        $this->assertFalse(remote_erasure::erase((int) $student->id));

        $this->assertCount(1, $erasure->calls);
        $this->assertSame([$key], $erasure->calls[0]['clientrequestids']);
        $this->assertSame(
            [['serviceid' => 'local_coursedynamicrules', 'userid' => (int) $student->id,
                'threadids' => ['thread-1'], 'clientrequestids' => [$key]]],
            $export->calls,
            'The erasure was not verified with an export of the same thread and key.'
        );
        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows, 'The seal was deleted although the service still returns the thread.');
        $this->assertNotNull($rows[0]->erasurerequested);
        $this->assertSame(1, (int) $rows[0]->erasureattempts);
        $this->assertSame(1767225600, (int) $rows[0]->erasurefailedat);
    }

    /**
     * The service says "erased" and its export returns nothing for those threads and keys: the seal goes.
     *
     * @return void
     */
    public function test_a_confirmed_erasure_verified_by_an_empty_export_deletes_the_seal(): void {
        global $DB;
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(true));
        $export = $this->use_export($this->nothing_left());
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $key = '0b5f1d6e-8c2a-4f3b-9d7e-1a2b3c4d5e6f';
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1', null, $key);

        $this->assertTrue(remote_erasure::erase((int) $student->id));

        $this->assertCount(1, $export->calls);
        $this->assertSame(0, $DB->count_records(remote_erasure::TABLE));
    }

    /**
     * An "exported" answer without the requests and threads lists does not verify the erasure.
     *
     * @return void
     */
    public function test_an_export_answer_without_its_lists_keeps_the_seal(): void {
        global $DB;
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(true));
        $this->use_export(['token_usage' => []]);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');

        $this->assertFalse(remote_erasure::erase((int) $student->id));

        $row = $DB->get_record(remote_erasure::TABLE, ['threadid' => 'thread-1'], '*', MUST_EXIST);
        $this->assertNotNull($row->erasurerequested, 'An incomplete export answer verified the erasure.');
        $this->assertSame(1, (int) $row->erasureattempts);
    }

    /**
     * An old service (no erased_thread_ids) never proves a row known only by its key, even with an empty export.
     *
     * CDR-PRIV-001-R2. A service that predates client_request_id ignores the key, so its "erased" and its
     * empty export say nothing about that request. The row with a thread id still goes the verified way.
     *
     * @return void
     */
    public function test_an_old_service_answer_never_proves_a_key_only_row(): void {
        global $DB;
        $this->mock_clock_with_frozen(1767225600);
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(true, false));
        $this->use_export($this->nothing_left());
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $threaded = remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');
        $keyonly = remote_erasure::record_request(
            9001,
            (int) $course->id,
            (int) $student->id,
            null,
            null,
            '0b5f1d6e-8c2a-4f3b-9d7e-1a2b3c4d5e6f'
        );

        $this->assertFalse(remote_erasure::erase((int) $student->id));

        $this->assertFalse($DB->record_exists(remote_erasure::TABLE, ['id' => $threaded]), 'The verified thread row stayed.');
        $row = $DB->get_record(remote_erasure::TABLE, ['id' => $keyonly]);
        $this->assertNotEmpty($row, 'A key-only row was taken as erased by a service that ignores keys.');
        $this->assertNotNull($row->erasurerequested);
        $this->assertSame(1, (int) $row->erasureattempts);
        $this->assertSame(1767225600, (int) $row->erasurefailedat);
    }

    /**
     * A service that answers erased_thread_ids understands keys: with an empty export a key-only row goes.
     *
     * @return void
     */
    public function test_a_key_aware_service_answer_with_an_empty_export_deletes_a_key_only_row(): void {
        global $DB;
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(true, true));
        $this->use_export($this->nothing_left());
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(
            9001,
            (int) $course->id,
            (int) $student->id,
            null,
            null,
            '0b5f1d6e-8c2a-4f3b-9d7e-1a2b3c4d5e6f'
        );

        $this->assertTrue(remote_erasure::erase((int) $student->id));
        $this->assertSame(0, $DB->count_records(remote_erasure::TABLE));
    }

    /**
     * When the verifying export cannot be retrieved, nothing is taken as erased.
     *
     * @return void
     */
    public function test_an_unavailable_verifying_export_keeps_the_seal(): void {
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(true));
        $this->use_export(null);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');

        $this->assertFalse(remote_erasure::erase((int) $student->id));

        $rows = remote_erasure::requests_of((int) $student->id);
        $this->assertCount(1, $rows, 'The seal was deleted without a verifying export.');
        $this->assertSame(1, (int) $rows[0]->erasureattempts);
    }

    /**
     * The third consecutive failure fires remote_erasure_failed, once: later failures do not repeat it.
     *
     * @return void
     */
    public function test_the_third_consecutive_failure_fires_the_event_exactly_once(): void {
        \core\di::set(remote_erasure_client::class, new fake_remote_erasure_client(false));
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-1');
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-2');
        remote_erasure::request_erasure((int) $student->id);

        $sink = $this->redirectEvents();
        for ($run = 1; $run <= 5; $run++) {
            $this->assertFalse(remote_erasure::send_pending((int) $student->id));
        }
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof remote_erasure_failed
        ));
        $sink->close();

        $this->assertCount(1, $events, 'The failure event must fire once, when the threshold is crossed.');
        $this->assertSame((int) $student->id, (int) $events[0]->relateduserid);
        $this->assertSame(\context_system::instance()->id, $events[0]->get_context()->id);
        $this->assertSame(remote_erasure::FAILURE_THRESHOLD, $events[0]->other['attempts']);
        $this->assertSame(2, $events[0]->other['requests']);
        $this->assertStringNotContainsString('thread-', $events[0]->get_description());
        foreach (remote_erasure::requests_of((int) $student->id) as $row) {
            $this->assertSame(5, (int) $row->erasureattempts);
        }
    }

    /**
     * Erasures pending longer than the configured number of days are listed for the administrator.
     *
     * @return void
     */
    public function test_erasures_pending_longer_than_the_setting_are_listed(): void {
        $clock = $this->mock_clock_with_frozen(1767225600);
        set_config('remoteerasurependingdays', 7, 'local_coursedynamicrules');
        $course = $this->getDataGenerator()->create_course();
        $old = $this->getDataGenerator()->create_user();
        $recent = $this->getDataGenerator()->create_user();
        $untouched = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $old->id, 'thread-old');
        remote_erasure::record_request(9001, (int) $course->id, (int) $recent->id, 'thread-recent');
        remote_erasure::record_request(9001, (int) $course->id, (int) $untouched->id, 'thread-untouched');

        remote_erasure::request_erasure((int) $old->id);
        $clock->bump(6 * DAYSECS);
        remote_erasure::request_erasure((int) $recent->id);
        $clock->bump(2 * DAYSECS);

        $overdue = remote_erasure::overdue_pending();

        $this->assertSame([(int) $old->id], array_map(fn($item) => (int) $item->userid, $overdue));
        $this->assertSame(1, (int) $overdue[0]->requests);
        $this->assertSame(1767225600, (int) $overdue[0]->firstrequested);
        $this->assertSame(1, remote_erasure::count_overdue_pending());
    }

    /**
     * The overdue list is capped at the rows the page shows; the count still covers every student.
     *
     * Internal security review (2026-11-20).
     *
     * @return void
     */
    public function test_the_overdue_list_is_capped_while_the_count_covers_everyone(): void {
        $clock = $this->mock_clock_with_frozen(1767225600);
        $course = $this->getDataGenerator()->create_course();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            remote_erasure::record_request(9001, (int) $course->id, (int) $user->id, 'thread-' . $i);
            remote_erasure::request_erasure((int) $user->id);
            $ids[] = (int) $user->id;
            $clock->bump(60);
        }
        $clock->bump(8 * DAYSECS);

        $listed = remote_erasure::overdue_pending(7, 2);

        $this->assertSame(array_slice($ids, 0, 2), array_map(fn($item) => (int) $item->userid, $listed));
        $this->assertSame(3, remote_erasure::count_overdue_pending(7));
    }

    /**
     * The pending-days setting refuses anything below one day.
     *
     * Internal security review (2026-11-20).
     *
     * @dataProvider pending_days_provider
     * @param string $value The value typed.
     * @param bool $valid Whether it is accepted.
     */
    public function test_the_pending_days_setting_accepts_only_whole_days_from_one(string $value, bool $valid): void {
        $setting = new \local_coursedynamicrules\admin\remote_erasure_days_setting();

        $this->assertSame($valid, $setting->validate($value) === true);
    }

    /**
     * Values for the pending-days setting.
     *
     * @return array
     */
    public static function pending_days_provider(): array {
        return [
            'one' => ['1', true],
            'default' => ['7', true],
            'zero' => ['0', false],
            'negative' => ['-3', false],
            'text' => ['abc', false],
        ];
    }

    /**
     * The settings page shows the overdue erasures read-only, with counts and dates but no thread id.
     *
     * @return void
     */
    public function test_the_settings_page_lists_the_overdue_erasures_without_thread_ids(): void {
        $clock = $this->mock_clock_with_frozen(1767225600);
        set_config('remoteerasurependingdays', 3, 'local_coursedynamicrules');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        remote_erasure::record_request(9001, (int) $course->id, (int) $student->id, 'thread-secret');
        remote_erasure::request_erasure((int) $student->id);
        $clock->bump(4 * DAYSECS);

        $data = (new \local_coursedynamicrules\admin\remote_erasure_pending_setting())->export_for_template();

        $this->assertTrue($data['hasitems']);
        $this->assertSame(1, $data['count']);
        $this->assertSame((int) $student->id, $data['items'][0]['userid']);
        $this->assertSame(0, $data['more']);
        $this->assertStringContainsString('3', $data['description']);
        $this->assertStringNotContainsString('thread-secret', json_encode($data));
    }

    /**
     * The scheduled task ships enabled, hourly, with a name that resolves.
     *
     * @return void
     */
    public function test_scheduled_task_ships_hourly_with_a_defined_name(): void {
        $tasks = [];
        foreach (\core\task\manager::load_default_scheduled_tasks_for_component('local_coursedynamicrules') as $task) {
            $tasks[get_class($task)] = $task;
        }
        $this->assertArrayHasKey(remote_erasure_task::class, $tasks);
        $this->assertFalse($tasks[remote_erasure_task::class]->get_disabled());
        $this->assertSame('*', $tasks[remote_erasure_task::class]->get_hour());

        $name = (new remote_erasure_task())->get_name();
        $this->assertDebuggingNotCalled();
        $this->assertSame(get_string('remote_erasure_task', 'local_coursedynamicrules'), $name);
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
     * Answer the verifying export with the given data.
     *
     * @param array|null $data What the service exports; null when it cannot be retrieved.
     * @return fake_remote_export_client
     */
    private function use_export(?array $data): fake_remote_export_client {
        $fake = new fake_remote_export_client($data);
        \core\di::set(remote_export_client::class, $fake);
        return $fake;
    }

    /**
     * An export in which nothing of the asked threads and keys is left.
     *
     * @return array
     */
    private function nothing_left(): array {
        return ['requests' => [], 'token_usage' => [], 'threads' => []];
    }
}
