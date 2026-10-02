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

namespace local_coursedynamicrules\helper;

/**
 * Two of the scheduled tasks walk every enrolled user of every course with an active rule, every minute.
 *
 * Today they load them all at once, with every user field (get_enrolled_users(..., 'u.*', ...)),
 * although the rule engine reads exactly one field of each user: its id (classes/core/rule.php,
 * the foreach in execute()). This helper is the bounded replacement: it yields ids, one page at a
 * time, and the tests below pin the properties the tasks depend on.
 *
 * 1. Same population as get_enrolled_users(..., $onlyactive = true) at rest: active enrolments
 *    only, each user once however many enrolments they hold.
 * 2. Bounded work per page: the number of queries grows with the number of pages, not with the
 *    number of users, and each page carries ids only.
 * 3. Keyset paging, not offset paging. The obvious alternative, paging get_enrolled_users() with
 *    its offset arguments, shifts when a user before the offset disappears mid-run: the next page
 *    then skips one user, and for a one-shot rule the skipped student is never notified. Paging by
 *    "id greater than the last seen" cannot skip: it does not depend on how many rows precede
 *    the cursor.
 * 4. Not a snapshot. Each page re-evaluates the enrolment, so a user removed AHEAD of the cursor
 *    during the run is not yielded, and a user enrolled ahead of it is. That is a deliberate
 *    difference from a single query, and it is pinned here so nobody relies on the opposite.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @covers      \local_coursedynamicrules\helper\enrolled_users
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrolled_users_test extends \advanced_testcase {
    /**
     * Enrol a user with the manual plugin, optionally suspended.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $user The user.
     * @param int $status ENROL_USER_ACTIVE or ENROL_USER_SUSPENDED.
     * @return void
     */
    private function enrol_manual(\stdClass $course, \stdClass $user, int $status = ENROL_USER_ACTIVE): void {
        global $DB;
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $manual->enrol_user($instance, $user->id, $studentroleid, 0, 0, $status);
    }

    /**
     * Remove a user's manual enrolment.
     *
     * @param \stdClass $course The course.
     * @param int $userid The user.
     * @return void
     */
    private function unenrol_manual(\stdClass $course, int $userid): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $userid);
    }

    /**
     * Drain the generator into a flat list of ids.
     *
     * @param \Generator $ids The generator under test.
     * @return int[]
     */
    private function drain(\Generator $ids): array {
        $out = [];
        foreach ($ids as $user) {
            $out[] = (int) $user->id;
        }
        return $out;
    }

    /**
     * Every active enrolled user is yielded exactly once, across page boundaries, and nobody else.
     *
     * Seeds: three plain students, one student enrolled twice (manual + self, both active), one
     * suspended. With a page of two, the four active users fill two pages plus one empty probe;
     * the dual enrolment must not double them and the suspended user must not appear - the same
     * population get_enrolled_users(..., true) returns today.
     *
     * @return void
     */
    public function test_yields_each_active_enrolled_user_once_across_pages(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $active = [];
        for ($i = 0; $i < 3; $i++) {
            $active[] = (int) $generator->create_and_enrol($course, 'student')->id;
        }

        // Enrolled twice, active in both.
        $twice = $generator->create_user();
        $this->enrol_manual($course, $twice);
        $self = enrol_get_plugin('self');
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $selfid = $self->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED, 'roleid' => $studentroleid]);
        $self->enrol_user($DB->get_record('enrol', ['id' => $selfid], '*', MUST_EXIST), $twice->id, $studentroleid);
        $active[] = (int) $twice->id;
        $this->assertCount(
            2,
            $DB->get_records('user_enrolments', ['userid' => $twice->id, 'status' => ENROL_USER_ACTIVE]),
            'Precondition: the dual enrolment is really two active enrolments, or DISTINCT is not exercised.'
        );

        // Suspended: enrolled, but not active.
        $suspended = $generator->create_user();
        $this->enrol_manual($course, $suspended, ENROL_USER_SUSPENDED);

        $yielded = $this->drain(enrolled_users::ids($context, 2));

        sort($active);
        sort($yielded);
        $this->assertSame($active, $yielded, 'Four active users, each once; the suspended one absent.');

        // And it is the same population core would return.
        $core = array_map('intval', array_keys(get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true)));
        sort($core);
        $this->assertSame($core, $yielded);
    }

    /**
     * Each yielded object carries the id and nothing else: the tasks need no other field.
     *
     * @return void
     */
    public function test_yields_ids_only(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_and_enrol($course, 'student');

        $seen = 0;
        foreach (enrolled_users::ids(\context_course::instance($course->id), 10) as $user) {
            $seen++;
            $this->assertSame(['id'], array_keys((array) $user));
            $this->assertIsInt($user->id);
        }
        $this->assertSame(1, $seen, 'The loop body must have run, or the assertions above proved nothing.');
    }

    /**
     * The number of queries grows with the number of pages, not with the number of users.
     *
     * Asserted as a difference between two page sizes over the same population, so the assertion
     * does not depend on how warm the caches are: seven users in pages of three take three page
     * queries, in one page of a hundred they take one. The difference must be exactly two. A
     * single get_enrolled_users() call wrapped in a generator would give a difference of zero.
     *
     * @return void
     */
    public function test_queries_scale_with_pages_not_users(): void {
        global $DB;
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        for ($i = 0; $i < 7; $i++) {
            $generator->create_and_enrol($course, 'student');
        }

        $before = $DB->perf_get_reads();
        $this->drain(enrolled_users::ids($context, 3));
        $smallpages = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        $this->drain(enrolled_users::ids($context, 100));
        $onepage = $DB->perf_get_reads() - $before;

        $this->assertSame(2, $smallpages - $onepage, 'Three pages of three versus one page of a hundred.');
    }

    /**
     * A user removed behind the cursor does not make the next page skip anyone.
     *
     * This is the test that separates keyset paging from offset paging: with pages of one, after
     * yielding A, unenrol A. Offset paging would then ask for row 2 of [B, C] - that is C - and
     * never yield B. Keyset paging asks for ids greater than A and yields B, then C.
     *
     * @return void
     */
    public function test_a_user_unenrolled_behind_the_cursor_is_not_skipped(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $a = $generator->create_and_enrol($course, 'student');
        $b = $generator->create_and_enrol($course, 'student');
        $c = $generator->create_and_enrol($course, 'student');
        $this->assertLessThan($b->id, $a->id, 'Precondition: ids ascend in creation order.');
        $this->assertLessThan($c->id, $b->id, 'Precondition: ids ascend in creation order.');

        $ids = enrolled_users::ids($context, 1);
        $ids->rewind();
        $this->assertSame((int) $a->id, (int) $ids->current()->id);

        $this->unenrol_manual($course, (int) $a->id);

        $rest = [];
        $ids->next();
        while ($ids->valid()) {
            $rest[] = (int) $ids->current()->id;
            $ids->next();
        }
        $this->assertSame([(int) $b->id, (int) $c->id], $rest, 'Nobody skipped after a removal behind the cursor.');
    }

    /**
     * A user removed ahead of the cursor is not yielded: the walk is live, not a snapshot.
     *
     * get_enrolled_users() evaluates the enrolment once, so it would still return C. This helper
     * re-evaluates it on every page, so a student unenrolled while the task runs is not evaluated
     * by that run. Pinned on purpose: it is the intended trade-off of paging, and the tasks must
     * not assume snapshot semantics.
     *
     * @return void
     */
    public function test_a_user_unenrolled_ahead_of_the_cursor_is_not_yielded(): void {
        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $a = $generator->create_and_enrol($course, 'student');
        $b = $generator->create_and_enrol($course, 'student');
        $c = $generator->create_and_enrol($course, 'student');

        $ids = enrolled_users::ids($context, 1);
        $ids->rewind();
        $this->assertSame((int) $a->id, (int) $ids->current()->id);

        $this->unenrol_manual($course, (int) $c->id);

        $rest = [];
        $ids->next();
        while ($ids->valid()) {
            $rest[] = (int) $ids->current()->id;
            $ids->next();
        }
        $this->assertSame([(int) $b->id], $rest, 'C was removed ahead of the cursor and must not be evaluated.');
    }

    /**
     * A course with nobody enrolled yields nothing and runs a single page query.
     *
     * @return void
     */
    public function test_an_empty_course_yields_nothing(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        // Resolved before the measured window: a context cache miss would cost a read of its own.
        $context = \context_course::instance($course->id);

        $before = $DB->perf_get_reads();
        $this->assertSame([], $this->drain(enrolled_users::ids($context, 50)));
        $this->assertSame(1, $DB->perf_get_reads() - $before);
    }

    /**
     * A page size below one is refused when the helper is CALLED, not when the result is iterated.
     *
     * A generator body runs nothing until its first iteration, so a check inside it would surface
     * far from the caller that passed the bad value, or never, if the result is not iterated.
     * The call below is deliberately not drained.
     *
     * @return void
     */
    public function test_a_page_size_below_one_is_refused_at_call_time(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\coding_exception::class);
        enrolled_users::ids(\context_course::instance($course->id), 0);
    }
}
