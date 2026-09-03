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
use local_coursedynamicrules\local\service\grade_isolation_service;
use local_coursedynamicrules\local\service\grade_register_service;

/**
 * Tests that a generated activity gaining a grade column shields everybody at that moment.
 *
 * Under "no grade" a compliant module creates no grade item, so `apply()` writes no exclusions -
 * correct then, wrong the instant a teacher sets a maximum grade and the column appears. Measured
 * before this observer existed: a bystander with 80% read 0% under Lowest grade and 40% under
 * Mean, with nothing in any log to explain it.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\observer\grade_item_created
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_item_created_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The student it was generated for. */
    private $target;

    /** @var \stdClass Never sees it. */
    private $bystander;

    /** @var \stdClass The baseline both students score 80 on. */
    private $base;

    /**
     * A course whose reinforcement was created UNGRADED, the way a compliant module leaves it.
     *
     * @param string $mode
     * @return \stdClass The generated activity.
     */
    private function seed(string $mode = grade_isolation_service::MODE_NOGRADE): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->target = $gen->create_and_enrol($this->course, 'student');
        $this->bystander = $gen->create_and_enrol($this->course, 'student');

        $this->base = $gen->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $this->base_item()->update_final_grade($this->target->id, 80);
        $this->base_item()->update_final_grade($this->bystander->id, 80);

        // Exactly what the action does: ask the module to be born ungraded.
        $payload = grade_isolation_service::prepare_payload(['grade' => 100], $mode);
        $ai = $gen->create_module('assign',
            ['course' => $this->course->id, 'grade' => $payload['grade']]);

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
     * The baseline's grade item.
     *
     * @return grade_item
     */
    private function base_item(): grade_item {
        $items = grade_item::fetch_all(['courseid' => $this->course->id, 'itemtype' => 'mod',
            'itemmodule' => 'assign', 'iteminstance' => $this->base->id]);

        return reset($items);
    }

    /**
     * Give an activity a maximum grade, the way a teacher editing its settings does.
     *
     * @param \stdClass $activity
     */
    private function teacher_sets_a_grade(\stdClass $activity): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $instance = $DB->get_record('assign', ['id' => $activity->id]);
        $instance->grade = 100;
        $instance->cmidnumber = '';
        $instance->courseid = $this->course->id;
        $DB->update_record('assign', $instance);
        assign_grade_item_update($instance);
    }

    /**
     * A user's course total as a percentage, under one aggregation, with empty counted as zero.
     *
     * @param int $userid
     * @param int $aggregation
     * @return float|null
     */
    private function total_under(int $userid, int $aggregation): ?float {
        $root = grade_category::fetch_course_category($this->course->id);
        $root->aggregation = $aggregation;
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
     * The scenario this observer exists for, driven by the real module call.
     *
     * Catches the observer being absent or registered under the wrong event name - and only this
     * can, because until the column appears there is nothing at all to assert about.
     */
    public function test_a_column_appearing_later_shields_everybody(): void {
        global $DB;
        $this->resetAfterTest(true);
        $ai = $this->seed();

        $this->assertEmpty(grade_isolation_service::gradable_items(
            $this->course->id, 'assign', (int) $ai->id),
            'the module complied, so there is no column yet and nothing was excluded');
        $this->assertSame(0, $DB->count_records('grade_grades', ['itemid' => -1]));

        $this->teacher_sets_a_grade($ai);

        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $this->assertCount(1, $items, 'the teacher created the column');
        $item = reset($items);

        foreach ([$this->bystander, $this->target] as $user) {
            $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
                ['itemid' => $item->id, 'userid' => $user->id]),
                'under "no grade" the column counts for nobody, its recipient included');
        }

        $this->assertEqualsWithDelta(80.0,
            $this->total_under((int) $this->bystander->id, GRADE_AGGREGATE_MIN), 0.05,
            'lowest grade is where an unshielded column is most brutal');
        $this->assertEqualsWithDelta(80.0,
            $this->total_under((int) $this->bystander->id, GRADE_AGGREGATE_MEAN), 0.05);
    }

    /**
     * Under "counts for its recipient" the appearing column spares exactly that student.
     */
    public function test_own_mode_spares_its_recipient_when_the_column_appears(): void {
        global $DB;
        $this->resetAfterTest(true);
        $ai = $this->seed(grade_isolation_service::MODE_OWN);

        // MODE_OWN does not ask for an ungraded module, so seed() already produced a column and
        // apply() already shielded. Delete the item to reach the "appears later" state honestly.
        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        reset($items)->delete('phpunit');
        $this->assertEmpty(grade_isolation_service::gradable_items(
            $this->course->id, 'assign', (int) $ai->id));

        $this->teacher_sets_a_grade($ai);

        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $item = reset($items);

        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $this->bystander->id]));
        $this->assertEmpty((int) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $this->target->id]),
            'the student it was generated for is the one it may count for');
    }

    /**
     * A grade column on somebody else's activity is left alone.
     *
     * Catches an observer that shields every column created anywhere on the site, which would make
     * the gradebook useless in any course this plugin has ever touched.
     */
    public function test_an_unrelated_activity_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->seed();

        $other = $this->getDataGenerator()->create_module('assign',
            ['course' => $this->course->id, 'grade' => 0]);
        $this->teacher_sets_a_grade($other);

        $items = grade_isolation_service::gradable_items(
            $this->course->id, 'assign', (int) $other->id);
        $item = reset($items);

        $this->assertSame(0, $DB->count_records_select('grade_grades',
            'itemid = ? AND excluded > 0', [$item->id]),
            'the plugin only owns the activities it generated');
    }

    /**
     * A course this plugin never touched costs one lookup and nothing else.
     */
    public function test_an_untouched_course_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $activity = $this->getDataGenerator()->create_module('assign',
            ['course' => $course->id, 'grade' => 100]);

        $items = grade_item::fetch_all(['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'assign', 'iteminstance' => $activity->id]);
        $item = reset($items);

        $this->assertSame(0, $DB->count_records_select('grade_grades',
            'itemid = ? AND excluded > 0', [$item->id]));
    }

    /**
     * Removing an activity's grade and putting it back must not lose the exclusions.
     *
     * This is why observing `grade_item_created` alone is enough, and it is measured rather than
     * assumed. Two transitions exist and they behave differently:
     *
     * - An activity that was NEVER graded has no grade_items row at all, so putting a grade on it
     *   fires grade_item_created - the case the observer covers.
     * - An activity that WAS graded and lost its grade KEEPS its row with gradetype = NONE (this
     *   is why ungraded activities still show up in the gradebook setup of a real course), and
     *   putting the grade back fires grade_item_updated, which nothing here observes.
     *
     * The second one needs no observer only because the exclusion rows survive the round trip. If
     * that ever stops being true, this test is what says so - and the fix would be to observe
     * grade_item_updated as well.
     */
    public function test_exclusions_survive_the_grade_being_removed_and_restored(): void {
        global $DB;
        $this->resetAfterTest(true);

        // MODE_OWN so the activity is born with a column and apply() shields immediately.
        $ai = $this->seed(grade_isolation_service::MODE_OWN);
        $items = grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id);
        $itemid = (int) reset($items)->id;

        $excluded = function () use ($DB, $itemid): int {
            return $DB->count_records_select('grade_grades', 'itemid = ? AND excluded > 0', [$itemid]);
        };
        $this->assertSame(1, $excluded(), 'the bystander is excluded, the recipient is not');

        // The teacher removes the grade. The row survives as "no grade".
        $instance = $DB->get_record('assign', ['id' => $ai->id]);
        $instance->grade = 0;
        $instance->cmidnumber = '';
        $instance->courseid = $this->course->id;
        $DB->update_record('assign', $instance);
        \assign_grade_item_update($instance);

        $this->assertSame(GRADE_TYPE_NONE,
            (int) $DB->get_field('grade_items', 'gradetype', ['id' => $itemid]),
            'the item stays, marked ungraded - it is not deleted');
        $this->assertSame(1, $excluded(), 'and the exclusion survives with it');

        // And back again.
        $this->teacher_sets_a_grade($ai);

        $this->assertSame(1, $excluded(),
            'so no observer is needed for this transition - the shield was never lost');
        $this->assertEqualsWithDelta(80.0,
            $this->total_under((int) $this->bystander->id, GRADE_AGGREGATE_MIN), 0.05);
    }
}
