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
 * The three enrolment-wide scheduled tasks walk a course's users in pages, and still reach all of them.
 *
 * SATG-SEC-003: each task used to load every enrolled user of every course with an active rule in
 * one array of full user records. These tests pin the replacement at the task level: with the
 * configured page size (taskbatchsize) the users are fetched page by page - observed through the
 * database's own query log, counting the paged query - and every user who should be reached still
 * is, across page boundaries.
 *
 * Five students with a page of two make three page queries: two full pages and a third one that
 * comes back short and ends the walk.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @covers      \local_coursedynamicrules\task\no_course_access_task
 * @covers      \local_coursedynamicrules\task\no_complete_activity_task
 * @covers      \local_coursedynamicrules\task\course_inactivity_task
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class paged_enrolment_walk_test extends \advanced_testcase {
    /**
     * The predicate only the paged walk emits; one occurrence per page fetched.
     *
     * The log prints the SQL after the driver rewrote the placeholders (? on MySQL, $1 on
     * PostgreSQL), so the needle stops before the parameter. Core's own enrolled-users query
     * filters on u.deleted, never on u.id.
     *
     * @var string
     */
    private const PAGE_QUERY = 'WHERE u.id > ';

    /** @var int Students per test. */
    private const STUDENTS = 5;

    /** @var int Page size under test: five students make three pages. */
    private const PAGE = 2;

    /** @var int Page queries expected for five students in pages of two. */
    private const PAGES = 3;

    /**
     * Enrol fresh students in the course.
     *
     * @param \stdClass $course The course.
     * @return \stdClass[] The students, in creation order.
     */
    private function enrol_students(\stdClass $course): array {
        $students = [];
        for ($i = 0; $i < self::STUDENTS; $i++) {
            $students[] = $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        return $students;
    }

    /**
     * Insert an active rule with one condition and a notification to students.
     *
     * @param int $courseid The course.
     * @param string $conditiontype Condition type.
     * @param array $conditionparams Condition params.
     * @return int The rule id.
     */
    private function create_rule(int $courseid, string $conditiontype, array $conditionparams): int {
        global $DB;
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => $conditiontype,
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => $conditiontype,
            'params' => json_encode($conditionparams),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'alert',
                'messagebody' => 'body',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
            ]),
        ]);
        return $ruleid;
    }

    /**
     * Run a task with the database query log on, returning everything it printed (mtrace + SQL).
     *
     * @param \core\task\scheduled_task $task The task.
     * @return string Captured output.
     */
    private function run_logged(\core\task\scheduled_task $task): string {
        global $DB;
        set_config('taskbatchsize', self::PAGE, 'local_coursedynamicrules');
        ob_start();
        $DB->set_debug(true);
        try {
            $task->execute();
        } finally {
            $DB->set_debug(false);
            $out = ob_get_clean();
        }
        return $out;
    }

    /**
     * The distinct recipients of the plugin's notifications, ascending.
     *
     * @param \phpunit_message_sink $sink The sink.
     * @return int[]
     */
    private function recipients(\phpunit_message_sink $sink): array {
        $ids = array_map(
            fn($m) => (int) $m->useridto,
            $sink->get_messages_by_component('local_coursedynamicrules')
        );
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Ascending ids of the given users.
     *
     * @param \stdClass[] $users The users.
     * @return int[]
     */
    private function ids(array $users): array {
        $ids = array_map(fn($u) => (int) $u->id, $users);
        sort($ids);
        return $ids;
    }

    /**
     * no_course_access: paged fetch, every student without access reached, threshold notice still counts.
     *
     * @return void
     */
    public function test_no_course_access_walks_users_in_pages_and_reaches_all_of_them(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $students = $this->enrol_students($course);
        foreach ($students as $student) {
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $student->id, 'courseid' => $course->id, 'timeaccess' => time() - (40 * DAYSECS),
            ]);
        }
        $this->create_rule($course->id, 'no_course_access', [
            'periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => time() - 100,
        ]);

        $sink = $this->redirectMessages();
        $log = $this->run_logged(new no_course_access_task());

        $this->assertSame(self::PAGES, substr_count($log, self::PAGE_QUERY), 'Five students in pages of two.');
        $this->assertSame($this->ids($students), $this->recipients($sink), 'Every student reached across pages.');
        $this->assertStringContainsString(
            'has ' . self::STUDENTS . ' enrolled users (over batch threshold ' . self::PAGE . ')',
            $log,
            'The over-threshold notice still reports the course size.'
        );
    }

    /**
     * no_complete_activity: paged fetch, every student who did not complete reached.
     *
     * @return void
     */
    public function test_no_complete_activity_walks_users_in_pages_and_reaches_all_of_them(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $students = $this->enrol_students($course);
        $this->create_rule($course->id, 'no_complete_activity', [
            'cmid' => $assign->cmid, 'expectedcompletiondate' => time() - 100,
        ]);

        $sink = $this->redirectMessages();
        $log = $this->run_logged(new no_complete_activity_task());

        $this->assertSame(self::PAGES, substr_count($log, self::PAGE_QUERY), 'Five students in pages of two.');
        $this->assertSame($this->ids($students), $this->recipients($sink), 'Every student reached across pages.');
    }

    /**
     * course_inactivity: paged fetch, students who completed the course are skipped, the rest reached.
     *
     * The completion filter must stream too: it may not turn the pages back into one array.
     *
     * @return void
     */
    public function test_course_inactivity_walks_users_in_pages_and_skips_completed_users(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1, 'startdate' => time() - (7 * DAYSECS),
        ]);
        $students = $this->enrol_students($course);
        $completed = array_slice($students, 0, 2);
        $inactive = array_slice($students, 2);
        foreach ($completed as $student) {
            $DB->insert_record('course_completions', (object) [
                'userid' => $student->id, 'course' => $course->id, 'timeenrolled' => time() - (7 * DAYSECS),
                'timestarted' => time() - (7 * DAYSECS), 'timecompleted' => time() - DAYSECS, 'reaggregate' => 0,
            ]);
        }
        $this->create_rule($course->id, 'course_inactivity', [
            'intervaltype' => 'custom', 'timeintervals' => '7', 'intervalunit' => 'days', 'basedatetype' => 'coursestart',
        ]);

        $sink = $this->redirectMessages();
        $log = $this->run_logged(new course_inactivity_task());

        $this->assertSame(self::PAGES, substr_count($log, self::PAGE_QUERY), 'Five students in pages of two.');
        $this->assertSame($this->ids($inactive), $this->recipients($sink), 'Only the three who did not complete.');
    }
}
