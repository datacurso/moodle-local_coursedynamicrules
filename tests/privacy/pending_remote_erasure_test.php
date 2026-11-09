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
use local_coursedynamicrules\local\fake_remote_erasure_client;
use local_coursedynamicrules\local\remote_erasure;
use local_coursedynamicrules\local\remote_erasure_client;
use local_coursedynamicrules\task\remote_erasure_task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fake_remote_erasure_client.php');

/**
 * A requested remote erasure is durable: it is recorded on the rows and sent until the service confirms.
 *
 * The course service has no erasure endpoint yet, so every erasure requested today stays pending for
 * as long as it takes to publish one. An adhoc task gives up after core's attempt limit; the request
 * is therefore recorded in local_coursedynamicrules_aireq.erasurerequested and the scheduled
 * remote_erasure_task sends it again on every run, whatever happened to any task before.
 *
 * CDR-PRIV-001-R2.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\remote_erasure
 * @covers     \local_coursedynamicrules\local\unavailable_remote_erasure_client
 * @covers     \local_coursedynamicrules\privacy\provider
 * @covers     \local_coursedynamicrules\task\remote_erasure_task
 * @covers     \local_coursedynamicrules\observer\course_deleted
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
                'scope' => remote_erasure_client::SCOPE_THREADS, 'threadids' => ['thread-a']]],
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
                'scope' => remote_erasure_client::SCOPE_USER, 'threadids' => ['thread-b']]],
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
                'scope' => remote_erasure_client::SCOPE_THREADS, 'threadids' => ['thread-a']]],
            $fake->calls
        );
    }

    /**
     * With the production client nothing is ever reported as erased: the rows stay, still pending.
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
}
