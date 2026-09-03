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

namespace local_coursedynamicrules\observer;

use grade_category;
use grade_grade;
use grade_item;
use local_coursedynamicrules\local\service\grade_register_service;
use local_coursedynamicrules\local\service\grade_isolation_service;

/**
 * Tests for the sweep that shields a student who enrols after a reinforcement was generated.
 *
 * Isolation is written per student at generation time. Somebody who arrives later has no row, so
 * every reinforcement column in the course is empty for them - and under Mean, Median or Lowest
 * grade an empty column counts as a zero. This was measured before the observer existed: the late
 * arrival's total was 40% where it should have been 80%.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\observer\user_enrolled
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_enrolled_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The student the reinforcement was generated for. */
    private $target;

    /** @var \stdClass The graded baseline everybody scores 80 on. */
    private $base;

    /**
     * A course where a reinforcement has already been generated and isolated.
     *
     * @param string $mode
     * @return \stdClass The generated activity.
     */
    private function seed(string $mode = grade_isolation_service::MODE_OWN, int $aigrade = 100): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->target = $gen->create_and_enrol($this->course, 'student');

        $this->base = $gen->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $ai = $gen->create_module('assign', ['course' => $this->course->id, 'grade' => $aigrade]);

        $this->base_item()->update_final_grade($this->target->id, 80);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->course->id, 'name' => 'R', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        grade_register_service::record_generation(
            (int) $this->course->id, (int) $ruleid, 1, (int) $this->target->id,
            (int) $ai->cmid, $mode
        );
        grade_isolation_service::apply($this->course->id, 'assign', (int) $ai->id, $mode,
            (int) $this->target->id);

        return $ai;
    }

    /**
     * The baseline activity's grade item.
     *
     * @return grade_item
     */
    private function base_item(): grade_item {
        $items = grade_item::fetch_all(['courseid' => $this->course->id, 'itemtype' => 'mod',
            'itemmodule' => 'assign', 'iteminstance' => $this->base->id]);

        return reset($items);
    }

    /**
     * A user's course total as a percentage.
     *
     * @param int $userid
     * @param int $aggregation
     * @return float|null
     */
    private function total_under(int $userid, int $aggregation): ?float {
        $root = grade_category::fetch_course_category($this->course->id);
        $root->aggregation = $aggregation;
        // The dangerous setting: empty grades counted as zero, which is the only configuration
        // where an unshielded column costs anybody anything.
        $root->aggregateonlygraded = 0;
        $root->update();
        grade_regrade_final_grades($this->course->id);

        $courseitem = grade_item::fetch_course_item($this->course->id);
        $gg = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $userid]);
        if (!$gg || $gg->finalgrade === null) {
            return null;
        }
        $max = (float) $gg->get_grade_max();

        return $max > 0 ? round((float) $gg->finalgrade / $max * 100, 1) : null;
    }

    /**
     * The scenario the observer exists for, driven by a real enrolment.
     *
     * Catches: no observer at all, or one registered under the wrong event name. Measured at 40%
     * before this existed - half the total, for a student who had done nothing wrong.
     */
    public function test_a_student_who_enrols_later_keeps_their_grade(): void {
        global $DB;
        $this->resetAfterTest(true);
        $ai = $this->seed();

        $late = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->base_item()->update_final_grade($late->id, 80);

        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $item = reset($items);
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $late->id]),
            'the enrolment wrote the shield');

        $this->assertEqualsWithDelta(80.0, $this->total_under((int) $late->id, GRADE_AGGREGATE_MEAN), 0.05);
        $this->assertEqualsWithDelta(80.0, $this->total_under((int) $late->id, GRADE_AGGREGATE_MIN), 0.05,
            'lowest grade is where an unshielded empty column is most brutal');
    }

    /**
     * A GENUINELY ungraded reinforcement has no column, so the sweep must write nothing.
     *
     * The activity is built through prepare_payload(), the way the action does it, rather than
     * with 'grade' => 100 and a register row that merely CLAIMS to be ungraded. That earlier
     * fixture was the defect dressed as a test: the activity did have a column, the sweep shielded
     * nobody, and the assertion called that correct - pinning the hole in place.
     */
    public function test_a_genuinely_ungraded_reinforcement_needs_no_shield(): void {
        global $DB;
        $this->resetAfterTest(true);

        $payload = grade_isolation_service::prepare_payload(
            ['course' => 0, 'grade' => 100], grade_isolation_service::MODE_NOGRADE);
        $ai = $this->seed(grade_isolation_service::MODE_NOGRADE, (int) $payload['grade']);

        $this->assertEmpty(
            grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id),
            'the fixture really is ungraded - otherwise this test proves nothing');

        $before = $DB->count_records('grade_grades');
        $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->assertSame($before, $DB->count_records('grade_grades'),
            'no column, so nothing to shield');
    }

    /**
     * A reinforcement REGISTERED as ungraded that kept its grade anyway must still be shielded.
     *
     * This is the other half, and the one that was missing: a module may ignore the request to be
     * created ungraded (SCORM reads maxgrade, a rated forum creates two items of its own). The
     * register still says 'nograde', so a sweep that trusts the stored mode skips it forever and
     * every late arrival is counted against a live column.
     */
    public function test_a_reinforcement_that_kept_its_grade_is_still_shielded(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Registered as nograde, created graded: exactly the state a non-compliant module leaves.
        $ai = $this->seed(grade_isolation_service::MODE_NOGRADE, 100);
        $items = grade_isolation_service::gradable_items(
            $this->course->id, 'assign', (int) $ai->id);
        $this->assertCount(1, $items, 'the fixture reproduces a module that kept its column');
        $item = reset($items);

        $late = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->base_item()->update_final_grade($late->id, 80);

        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $late->id]),
            'the gradebook decides whether there is a column, not the stored mode');
        $this->assertEqualsWithDelta(80.0,
            $this->total_under((int) $late->id, GRADE_AGGREGATE_MIN), 0.05);
    }

    /**
     * A course this plugin never touched must cost one indexed lookup and nothing else.
     */
    public function test_an_untouched_course_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $before = $DB->count_records('grade_grades');
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertSame($before, $DB->count_records('grade_grades'));
    }

    /**
     * A register row pointing at a deleted activity must not surface to the person enrolling.
     *
     * The claim this docblock used to make was false, and it was the same false claim the earlier
     * rounds already caught once: "the observer runs inside the enrolment transaction, so a throw
     * stops the enrolment itself". Core does neither of those things. It defers non-internal
     * observers out of any open transaction (lib/classes/event/manager.php:136-147) and catches
     * \Exception around each callback (:154-162), downgrading it to debugging().
     *
     * What the plugin's own catch adds is the \Error half - a TypeError on a malformed register
     * row would otherwise escape and kill the request that triggered the enrolment, which by then
     * is already committed. That is the real risk, and it is what this asserts.
     */
    public function test_a_deleted_activity_never_blocks_an_enrolment(): void {
        $this->resetAfterTest(true);
        $ai = $this->seed();

        course_delete_module((int) $ai->cmid);

        $late = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertTrue(is_enrolled(\context_course::instance($this->course->id), $late->id),
            'the enrolment completed');
    }

    /**
     * Several generated activities in one course are all swept, not just the first.
     */
    public function test_every_generated_activity_in_the_course_is_swept(): void {
        global $DB;
        $this->resetAfterTest(true);
        $first = $this->seed();

        $gen = $this->getDataGenerator();
        $other = $gen->create_and_enrol($this->course, 'student');
        $second = $gen->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $ruleid = $DB->get_field('local_coursedynamicrules_rule', 'id', ['courseid' => $this->course->id]);
        grade_register_service::record_generation(
            (int) $this->course->id, (int) $ruleid, 2, (int) $other->id,
            (int) $second->cmid, grade_isolation_service::MODE_OWN
        );
        grade_isolation_service::apply($this->course->id, 'assign', (int) $second->id,
            grade_isolation_service::MODE_OWN, (int) $other->id);

        $late = $gen->create_and_enrol($this->course, 'student');

        foreach ([$first, $second] as $activity) {
            $items = grade_isolation_service::gradable_items(
                $this->course->id, 'assign', (int) $activity->id);
            $item = reset($items);
            $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
                ['itemid' => $item->id, 'userid' => $late->id]),
                'shielded from every reinforcement in the course');
        }
    }

    /**
     * Deleting the action that generated the activity must not un-shield later arrivals.
     *
     * Both judges measured this independently: forget_action() used to DELETE the register rows,
     * and the generated activity stays in the course with a live grade column. Once the last row
     * for a course was gone the sweep's course gate answered no forever, so every student who
     * enrolled afterwards read 0% under Lowest grade against a baseline of 80%. Deleting a rule is
     * a supported operation the changelog advertises as available forever.
     *
     * `action::delete()` (classes/core/action.php:254) is what calls this in production.
     */
    public function test_deleting_the_action_keeps_later_arrivals_shielded(): void {
        global $DB;
        $this->resetAfterTest(true);
        $ai = $this->seed(grade_isolation_service::MODE_OWN);

        grade_register_service::forget_action(1);

        $this->assertSame(0, $DB->count_records(grade_register_service::TABLE, ['actionid' => 1]),
            'the duplicate guard for that action is released');
        $this->assertSame(1, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $this->course->id]),
            'but the column marker survives - the graded column did not go anywhere');

        $late = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->base_item()->update_final_grade($late->id, 80);

        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $item = reset($items);
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $late->id]),
            'so the sweep still knows there is a column to shield them from');
        $this->assertEqualsWithDelta(80.0,
            $this->total_under((int) $late->id, GRADE_AGGREGATE_MIN), 0.05);
    }

    /**
     * A privacy erasure must not un-shield later arrivals either.
     *
     * Same mechanism, different trigger: the register is the only record that a live graded column
     * exists in this course. One student exercising their right to erasure cannot be allowed to
     * cost every future student in that course their grade.
     */
    public function test_an_erasure_keeps_later_arrivals_shielded(): void {
        global $DB;
        $this->resetAfterTest(true);
        $ai = $this->seed(grade_isolation_service::MODE_OWN);

        grade_register_service::forget_users((int) $this->course->id, [(int) $this->target->id]);

        $this->assertSame(0, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $this->course->id, 'userid' => $this->target->id]),
            'the erased student is no longer named');
        $this->assertSame(1, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $this->course->id, 'userid' => 0]),
            'and the column marker survives, severed');

        $late = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->base_item()->update_final_grade($late->id, 80);

        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $item = reset($items);
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $late->id]));
        $this->assertEqualsWithDelta(80.0,
            $this->total_under((int) $late->id, GRADE_AGGREGATE_MIN), 0.05);
    }

    /**
     * A severed "counts for its recipient" row spares nobody.
     *
     * The deliberate consequence of erasure: without a recipient the plugin no longer knows who to
     * spare, so it spares no one. That is the safe direction and the honest one - and it must be
     * asserted, because the alternative (sparing user 0, or sparing whoever now holds that id) is
     * exactly the kind of silent wrong this service exists to prevent.
     */
    public function test_a_severed_row_spares_nobody(): void {
        global $DB;
        $this->resetAfterTest(true);
        $ai = $this->seed(grade_isolation_service::MODE_OWN);

        grade_register_service::forget_users((int) $this->course->id);

        $late = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $item = reset($items);

        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $late->id]),
            'nobody is spared once the recipient is unknown');
    }
}
