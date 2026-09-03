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

namespace local_coursedynamicrules\local\service;

require_once(__DIR__ . '/../../fixtures/racing_grade_isolation_service.php');

use grade_category;
use grade_grade;
use grade_item;

/**
 * Tests for the grade isolation service.
 *
 * Each test names the defect it would catch, because an assertion that survives the feature being
 * deleted is not an assertion.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\service\grade_isolation_service
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_isolation_service_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass Receives the generated activity. */
    private $target;
    /** @var \stdClass Never sees it. */
    private $bystander;
    /** @var \stdClass The generated assign. */
    private $ai;

    /**
     * Both students score 80/100 on a baseline; only the target is graded 50/100 on the generated
     * activity, which is exactly the state a per-user restriction leaves the gradebook in.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->target = $gen->create_and_enrol($this->course, 'student');
        $this->bystander = $gen->create_and_enrol($this->course, 'student');

        $base = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Base', 'grade' => 100]);
        $this->ai = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'AI', 'grade' => 100]);

        $this->item_for('assign', $base->id)->update_final_grade($this->target->id, 80);
        $this->item_for('assign', $base->id)->update_final_grade($this->bystander->id, 80);
        $this->item_for('assign', $this->ai->id)->update_final_grade($this->target->id, 50);
    }

    /**
     * First grade item of a module instance, or null once it stops being gradable.
     *
     * @param string $modname
     * @param int $instance
     * @return grade_item|null
     */
    private function item_for(string $modname, int $instance): ?grade_item {
        $items = grade_item::fetch_all([
            'courseid' => $this->course->id, 'itemtype' => 'mod',
            'itemmodule' => $modname, 'iteminstance' => $instance,
        ]);
        return $items ? reset($items) : null;
    }

    /**
     * The record shape a module needs to push its own grade item.
     *
     * assign_grade_item_update() reads cmidnumber and courseid, which a bare table row does not
     * carry; core builds them from the course module.
     *
     * @param int $instance
     * @return \stdClass
     */
    private function module_record_for_grade_push(int $instance): \stdClass {
        global $DB;

        $record = $DB->get_record('assign', ['id' => $instance], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $instance, $this->course->id, false, MUST_EXIST);
        $record->courseid = $this->course->id;
        $record->cmidnumber = $cm->idnumber;

        return $record;
    }

    /**
     * Set the root aggregation and empty-grade flag, then regrade.
     *
     * @param int $aggregation
     * @param int $onlygraded
     */
    private function configure_root(int $aggregation, int $onlygraded): void {
        $root = grade_category::fetch_course_category($this->course->id);
        $root->aggregation = $aggregation;
        $root->aggregateonlygraded = $onlygraded;
        $root->update();
        grade_regrade_final_grades($this->course->id);
    }

    /**
     * A user's course-total percentage.
     *
     * @param int $userid
     * @return float|null
     */
    private function percentage(int $userid): ?float {
        $courseitem = grade_item::fetch_course_item($this->course->id);
        $gg = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $userid]);
        if (!$gg || $gg->finalgrade === null) {
            return null;
        }
        $max = (float) $gg->get_grade_max();
        return $max > 0 ? round((float) $gg->finalgrade / $max * 100, 1) : 0.0;
    }

    /**
     * The default mode asks the module to be created ungraded, and that has to SURVIVE the module
     * writing its own grade item afterwards.
     *
     * Catches the defect this test was rewritten for: patching the item's gradetype after creation
     * looks correct and passes a regrade, but every module re-asserts its gradetype from its own
     * setting on each grade push (mod/assign/lib.php:1044), so the column came back the first time
     * the recipient used the activity - the one moment it mattered.
     *
     * Deliberately asserts nothing about course totals: this course already carries the graded
     * activity setUp() builds, so a percentage measured here would be answering a different
     * question. The (mode x aggregation) totals are measured in their own course, one per cell, by
     * test_protection_matrix_matches_the_gradebook().
     */
    public function test_nograde_survives_the_module_rewriting_its_own_item(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        // What prepare_payload() hands to the creation service.
        $payload = grade_isolation_service::prepare_payload(
            ['name' => 'AI'], grade_isolation_service::MODE_NOGRADE
        );
        $this->assertSame(0, $payload['grade'], 'the module is asked to be born ungraded');

        $ai = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id, 'name' => 'AI ungraded', 'grade' => $payload['grade'],
        ]);
        $this->assertCount(0,
            grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id),
            'nothing to grade the moment it is created');

        // The module pushes its own grade item, exactly as it does on every submission and every
        // grade save. This is the step the previous test never took.
        assign_grade_item_update($this->module_record_for_grade_push((int) $ai->id));

        $this->assertCount(0,
            grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id),
            'and still nothing after the module rewrote its item');
    }

    /**
     * The mechanism that was replaced, pinned as a negative control so nobody reintroduces it.
     *
     * Patching the grade item is undone by the module. If this test ever goes green, someone has
     * gone back to switching grading off after creation.
     */
    public function test_patching_the_item_after_creation_does_not_hold(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $ai = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id, 'name' => 'AI graded', 'grade' => 100,
        ]);

        grade_update('local_coursedynamicrules', $this->course->id, 'mod', 'assign', $ai->id, 0, null,
            ['gradetype' => GRADE_TYPE_NONE]);
        $this->assertCount(0,
            grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id),
            'the patch looks like it worked');

        assign_grade_item_update($this->module_record_for_grade_push((int) $ai->id));

        $this->assertCount(1,
            grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $ai->id),
            'and the module put it straight back - which is why the mechanism changed');
    }

    /**
     * Reaching apply() with gradable items in the default mode means the module ignored the
     * request, and the caller must be able to tell.
     *
     * Catches: returning a cheerful count for a failure, which is what let the previous version
     * report success while the column was live.
     */
    public function test_apply_reports_a_module_that_kept_its_grade(): void {
        $applied = grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_NOGRADE
        );

        $this->assertSame(2, $applied,
            'the module kept its column, so both students are shielded from it - nobody at all');

        global $DB;
        $item = $this->item_for('assign', (int) $this->ai->id);
        foreach ([$this->bystander, $this->target] as $user) {
            $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
                ['itemid' => $item->id, 'userid' => $user->id]),
                'asking for no grade is delivered by the shield, not by the module complying');
        }

        $this->configure_root(GRADE_AGGREGATE_MIN, 0);
        $this->assertEqualsWithDelta(80.0, $this->percentage($this->bystander->id), 0.05);
        $this->assertEqualsWithDelta(80.0, $this->percentage($this->target->id), 0.05,
            'under "no grade" the column moves nobody, its recipient included');
    }

    /**
     * The bystander keeps their grade whatever the "exclude empty grades" box says, and the
     * recipient's own column counts for the recipient.
     *
     * Catches: the exclusion silently not being written. Without it the exclude=0 row drops to 40
     * and this fails.
     *
     * @param int $onlygraded
     * @dataProvider empty_grade_flag_provider
     */
    public function test_own_grade_protects_bystanders_whatever_the_empty_grade_flag(int $onlygraded): void {
        global $DB;

        // The register row the action writes, because admitting the recipient reads the mode from
        // it. Calling apply() alone is not the real flow and cannot exercise the admission.
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->course->id, 'name' => 'R', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        grade_register_service::record_generation(
            (int) $this->course->id, (int) $ruleid, 1, (int) $this->target->id,
            (int) $this->ai->cmid, grade_isolation_service::MODE_OWN
        );

        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );
        $this->configure_root(GRADE_AGGREGATE_SUM, $onlygraded);

        $this->assertEqualsWithDelta(80.0, $this->percentage($this->bystander->id), 0.05,
            'the bystander keeps their grade whatever that box says');

        // setUp() graded the recipient 50 on this activity, so their 80 + 50 out of 200 is what
        // "counts for them" means: an ordinary activity, not extra credit.
        $this->assertEqualsWithDelta(65.0, $this->percentage($this->target->id), 0.05,
            'and it counts for the student it was generated for, as an ordinary activity');
    }

    /**
     * Both states of the gradebook setting the plugin does not control.
     *
     * @return array
     */
    public static function empty_grade_flag_provider(): array {
        return ['excluir vacias ON' => [1], 'excluir vacias OFF' => [0]];
    }

    /**
     * An unknown or absent mode must resolve to the safe default, never to "leave it counting".
     *
     * Catches: a rule saved before this option existed silently keeping the broken behaviour.
     */
    public function test_unknown_mode_falls_back_to_nograde(): void {
        $this->assertSame(grade_isolation_service::MODE_NOGRADE, grade_isolation_service::clean_mode(null));
        $this->assertSame(grade_isolation_service::MODE_NOGRADE, grade_isolation_service::clean_mode('nonsense'));
        $this->assertSame(grade_isolation_service::MODE_NOGRADE, grade_isolation_service::clean_mode(''));
        $this->assertSame(grade_isolation_service::MODE_OWN, grade_isolation_service::clean_mode('own'));
    }

    /**
     * The form asks two questions; exactly one function turns them back into a stored mode.
     *
     * Catches: the form and the validator disagreeing about what the teacher chose - the classic
     * seam bug, where each file is green and the value breaks while crossing between them.
     */
    public function test_the_yes_no_choice_maps_to_a_mode(): void {
        $svc = grade_isolation_service::class;

        // Every shape the form or a stored param can arrive in. Anything falsy is "counts for
        // nobody", because that is the answer that cannot cost anybody points.
        foreach ([0, '0', null, '', false] as $no) {
            $this->assertSame($svc::MODE_NOGRADE, $svc::mode_from_choice($no),
                'a falsy answer never turns into a grade that counts');
        }
        foreach ([1, '1', true] as $yes) {
            $this->assertSame($svc::MODE_OWN, $svc::mode_from_choice($yes));
        }

        $this->assertSame([$svc::MODE_NOGRADE, $svc::MODE_OWN], $svc::modes(),
            'two modes, and no third one hiding in the list');
    }

    /**
     * Every student in the gradebook gets an exclusion row except the one it was generated for.
     *
     * Catches the mechanism failing to write anything, and catches it writing over the recipient
     * too - which would make "counts for that student" count for nobody.
     */
    public function test_exclusion_is_written_for_everybody_but_the_recipient(): void {
        global $DB;

        $third = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $shielded = grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );
        $this->assertSame(2, $shielded, 'the other two, not the student it was generated for');

        $item = $this->item_for('assign', (int) $this->ai->id);
        $excluded = $DB->get_records_select_menu('grade_grades',
            'itemid = ? AND excluded > 0', [$item->id], '', 'userid, excluded');

        $this->assertArrayHasKey((int) $this->bystander->id, $excluded);
        $this->assertArrayHasKey((int) $third->id, $excluded);
        $this->assertArrayNotHasKey((int) $this->target->id, $excluded,
            'the one student it was generated for is the one it may still count for');
    }

    /**
     * Running it twice writes nothing new and destroys no grade that already exists.
     *
     * Catches the defect the design was built to avoid: creating the row through
     * update_final_grade($userid, null) - the way core's own singleview does it - wipes an existing
     * grade to null. The recipient of THIS activity has a 50 in setUp; a second pass must not eat it.
     */
    public function test_applying_twice_changes_nothing_and_destroys_no_grade(): void {
        global $DB;

        $item = $this->item_for('assign', (int) $this->ai->id);
        grade_isolation_service::apply($this->course->id, 'assign', (int) $this->ai->id,
            grade_isolation_service::MODE_OWN, (int) $this->target->id);

        $rows = $DB->count_records('grade_grades', ['itemid' => $item->id]);
        $again = grade_isolation_service::apply($this->course->id, 'assign', (int) $this->ai->id,
            grade_isolation_service::MODE_OWN, (int) $this->target->id);

        $this->assertSame(0, $again, 'nothing left to shield the second time');
        $this->assertSame($rows, $DB->count_records('grade_grades', ['itemid' => $item->id]),
            'no duplicate rows');
        $this->assertEqualsWithDelta(50.0, (float) $DB->get_field('grade_grades', 'finalgrade',
            ['itemid' => $item->id, 'userid' => $this->target->id]), 0.01,
            'the grade the recipient had earned survived being excluded');
    }

    /**
     * A shielded student can still be graded by the module afterwards.
     *
     * Catches the defect that would kill the carrying modes in silence: creating the row through
     * the grade API leaves `overridden` set, and grade_update() - the call every module makes when
     * a teacher saves a grade - refuses to touch an overridden grade. The recipient of a carrying
     * mode is excluded at creation time and graded later, so if this breaks, that mode never works
     * and nothing reports it.
     */
    public function test_a_shielded_student_can_still_be_graded_by_the_module(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        grade_isolation_service::apply($this->course->id, 'assign', (int) $this->ai->id,
            grade_isolation_service::MODE_OWN, (int) $this->target->id);

        $item = $this->item_for('assign', (int) $this->ai->id);
        $this->assertEmpty((int) $DB->get_field('grade_grades', 'overridden',
            ['itemid' => $item->id, 'userid' => $this->bystander->id]),
            'shielding must not leave the row overridden');

        grade_update('mod/assign', $this->course->id, 'mod', 'assign', (int) $this->ai->id, 0,
            ['userid' => $this->bystander->id, 'rawgrade' => 88.0]);

        $row = $DB->get_record('grade_grades',
            ['itemid' => $item->id, 'userid' => $this->bystander->id]);
        $this->assertEqualsWithDelta(88.0, (float) $row->finalgrade, 0.01,
            'the module could write the grade');
        $this->assertNotEmpty((int) $row->excluded,
            'and it is still excluded from the totals');
    }

    /**
     * Two rules with different modes in one course must not disturb each other.
     *
     * One course with two rules is the normal use of this plugin, not an edge case. Under the
     * previous design both landed in shared grade categories and the newest generation rewrote the
     * coefficients of everything already inside; per-item exclusion cannot do that, and this pins it.
     */
    public function test_two_modes_in_one_course_do_not_disturb_each_other(): void {
        global $DB;

        $second = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id, 'name' => 'AI 2', 'grade' => 100,
        ]);

        grade_isolation_service::apply($this->course->id, 'assign', (int) $this->ai->id,
            grade_isolation_service::MODE_OWN, (int) $this->target->id);
        grade_isolation_service::apply($this->course->id, 'assign', (int) $second->id,
            grade_isolation_service::MODE_OWN, (int) $this->bystander->id);

        // Each activity spares its own recipient and shields the other student, and the second
        // generation does not disturb the first. Under the previous design both landed in shared
        // grade categories and the newest generation rewrote the coefficients of everything
        // already inside; per-student exclusion cannot reach across activities like that.
        $first = $this->item_for('assign', (int) $this->ai->id);
        $this->assertEmpty((int) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $first->id, 'userid' => $this->target->id]),
            'the first activity still spares its own recipient');
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $first->id, 'userid' => $this->bystander->id]));

        $other = $this->item_for('assign', (int) $second->id);
        $this->assertEmpty((int) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $other->id, 'userid' => $this->bystander->id]),
            'and the second spares its own');
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $other->id, 'userid' => $this->target->id]));

        $this->assertSame(1, $DB->count_records('grade_categories', ['courseid' => $this->course->id]),
            'and no plugin grade categories are created at all');
    }

    /**
     * A module may create more than one grade item. Catches: acting on the first one only, leaving
     * a second column outside the category and still hitting everybody's total.
     */
    public function test_every_gradable_item_of_the_activity_is_handled(): void {
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id, 'grade_forum' => 100, 'scale' => 100, 'assessed' => 1,
        ]);
        $before = grade_isolation_service::gradable_items($this->course->id, 'forum', (int) $forum->id);
        $this->assertGreaterThan(1, count($before), 'this forum is meant to produce several items');

        // A graded mode, because the default one no longer acts here: it asks the module to be
        // born ungraded and then only verifies, so there is nothing for it to shield.
        $handled = grade_isolation_service::apply(
            $this->course->id, 'forum', (int) $forum->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );

        // One shielded student on each of the forum's items: the bystander. The recipient is the
        // one person the column may reach.
        $this->assertSame(count($before), $handled);

        global $DB;
        foreach (grade_isolation_service::gradable_items($this->course->id, 'forum', (int) $forum->id) as $item) {
            $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
                ['itemid' => $item->id, 'userid' => $this->bystander->id]),
                'every one of the activity\'s items is shielded, not just the first');
        }
    }

    /**
     * A reinforcement graded by a SCALE is shielded like any other.
     *
     * Scales are a separate grade type with their own range - Moodle sets grademin to 1 and
     * grademax to the number of options - and gradable_items() has to admit them or the whole
     * mechanism silently skips every scale-graded activity, leaving its column live for the course.
     */
    public function test_a_scale_graded_reinforcement_is_shielded_too(): void {
        global $DB;

        $scaleid = (int) $this->getDataGenerator()->create_scale([
            'scale' => 'Insuficiente,Suficiente,Bien,Excelente',
        ])->id;
        $item = $this->item_for('assign', (int) $this->ai->id);
        $item->gradetype = GRADE_TYPE_SCALE;
        $item->scaleid = $scaleid;
        $item->update();

        $found = grade_isolation_service::gradable_items(
            $this->course->id, 'assign', (int) $this->ai->id);
        $this->assertCount(1, $found, 'a scale-graded item still counts as gradable');

        $shielded = grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );
        $this->assertSame(1, $shielded);

        $item = $this->item_for('assign', (int) $this->ai->id);
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $this->bystander->id]),
            'a scale-graded item is shielded like any other');
        $this->assertEmpty((int) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $this->target->id]));
    }

    /**
     * A module that kept its grade despite being asked not to is shielded anyway.
     *
     * The falsifier for the worst defect the adversarial review found, and for the false premise
     * underneath it. apply() used to detect the non-compliant module and return an error code
     * without shielding anybody - and non-compliance is the NORMAL case: of the five module types
     * local_coursegen can generate, not one reads the `grade` field this plugin sets. Measured on a
     * rated forum before the fix: a bystander with 80% fell to 26.7% under Mean and 0% under
     * Lowest grade.
     */
    public function test_a_module_that_kept_its_grade_is_reported_and_still_shielded(): void {
        global $DB;

        $applied = grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_NOGRADE
        );

        $this->assertSame(2, $applied,
            'both students shielded: under "no grade" the column counts for nobody at all');

        $item = $this->item_for('assign', (int) $this->ai->id);
        foreach ([$this->bystander, $this->target] as $user) {
            $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
                ['itemid' => $item->id, 'userid' => $user->id]),
                'the request to be ungraded is delivered by the shield, not by the module');
        }

        $this->configure_root(GRADE_AGGREGATE_MIN, 0);
        $this->assertEqualsWithDelta(80.0, $this->percentage($this->bystander->id), 0.05,
            'lowest grade is where an unshielded column is most brutal');
    }

    /**
     * Gradable items and nobody to shield is a failure, not a clean run.
     *
     * apply() used to return 0 for both "nothing needed doing" and "the protection could not be
     * built", so the caller reported success on a course where no row had been written.
     */
    public function test_no_gradebook_users_reads_as_a_failure(): void {
        global $CFG;

        $CFG->gradebookroles = '';

        $applied = grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );

        $this->assertLessThan(0, $applied,
            'zero rows written must never look like zero rows needed');
    }

    /**
     * A suspended enrolment is still shielded, because the gradebook still counts it.
     *
     * gradebook_users() hard-coded onlyactive = true while claiming to lift core's definition,
     * which passes false (grade/lib.php:93). grade_category::generate_grades() applies no
     * enrolment filter at all - it reads grade_grades rows - so a suspended student kept being
     * aggregated and stopped being protected, and nothing would ever revisit them.
     */
    public function test_a_suspended_student_is_still_shielded(): void {
        global $DB;

        $suspended = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->item_for('assign', (int) $this->ai->id);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED,
            ['userid' => $suspended->id]);

        $this->assertContains((int) $suspended->id,
            grade_isolation_service::gradebook_users((int) $this->course->id),
            'core aggregates them, so this must shield them');

        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );

        $item = $this->item_for('assign', (int) $this->ai->id);
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $suspended->id]));
    }

    /**
     * An item created inside a category is put back at the root before anything is written.
     */
    public function test_apply_puts_the_item_at_the_root_first(): void {
        $cat = new grade_category(['courseid' => $this->course->id, 'fullname' => 'Alguna'], false);
        $cat->insert();
        $item = $this->item_for('assign', (int) $this->ai->id);
        $item->set_parent((int) $cat->id);
        $this->assertSame((int) $cat->id, (int) $this->item_for('assign', (int) $this->ai->id)->categoryid);

        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN,
            (int) $this->target->id
        );
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER, 'moving it back is announced');

        $this->assertSame(
            (int) grade_category::fetch_course_category($this->course->id)->id,
            (int) $this->item_for('assign', (int) $this->ai->id)->categoryid,
            'the shield only holds at the root, so apply() puts it there rather than assuming it'
        );
    }

    /**
     * Somebody else winning the race must not cost everybody else their shield.
     *
     * grade_grades has a unique key on (userid, itemid), and insert_records() batches into
     * multi-row INSERTs - so ONE collision aborts the whole chunk and, without the fallback in
     * insert_shields(), leaves the entire cohort unprotected with the exception downgraded to a
     * developer-debug line. The window is real and not exotic: core inserts those rows itself
     * during any regrade (grade_category.php:655-668), and creating the module leaves a regrade
     * pending, so a teacher opening the grader report is enough.
     *
     * The previous version of this test claimed a duplicated id in the user list "produces exactly
     * that collision deterministically". It does not, and that made the claim worse than useless:
     * array_unique() in exclude_all_but() removes the duplicate BEFORE anything is queued, so the
     * batch never collided, the fallback was never entered, and the whole concurrency mechanism
     * shipped with zero coverage behind a test that read as if it had some.
     *
     * This opens the window where it actually exists - between the read and the write - through
     * racing_grade_isolation_service. Falsifier: delete the catch in insert_shields() and this
     * test errors on an uncaught dml_exception.
     */
    public function test_losing_the_race_does_not_cost_the_others_their_shield(): void {
        global $DB;

        // The module comes FIRST and the students AFTER, and that order is the whole setup: core
        // creates a grade_grades row for everybody already enrolled the moment the item exists
        // (grade_category.php:655-668), so students enrolled beforehand go down the update branch
        // and queue nothing. Only students who arrive later still need a row inserted - and a
        // batch of one cannot show that the OTHER rows survive a collision, which is the property
        // under test. Three latecomers, one of whom loses the race.
        $fresh = $this->getDataGenerator()->create_module('assign',
            ['course' => $this->course->id, 'name' => 'Fresh', 'grade' => 100]);
        $item = $this->item_for('assign', (int) $fresh->id);

        $late = [];
        for ($i = 0; $i < 3; $i++) {
            $late[] = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        }
        $users = array_map(fn($u) => (int) $u->id, $late);
        foreach ($users as $userid) {
            $this->assertFalse($DB->record_exists('grade_grades',
                ['itemid' => $item->id, 'userid' => $userid]),
                'the fixture only means anything if these rows still have to be inserted');
        }
        $before = $DB->count_records('grade_grades', ['itemid' => $item->id]);

        racing_grade_isolation_service::$loser = $users[0];
        racing_grade_isolation_service::$injected = 0;
        $written = racing_grade_isolation_service::exclude_all_but($item, $users, null);

        $this->assertSame(1, racing_grade_isolation_service::$injected,
            'the race has to have actually been lost, or this test proves nothing');
        $this->assertSame(3, $written, 'two inserted one at a time, and the lost one flagged');

        foreach ($users as $userid) {
            $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
                ['itemid' => $item->id, 'userid' => $userid]),
                'losing one row did not take anybody else down with it');
        }
        $this->assertSame($before + 3, $DB->count_records('grade_grades', ['itemid' => $item->id]),
            'and the row somebody else wrote was flagged, not duplicated');
    }

    /**
     * Losing the race on a row that already carries a grade must not erase it.
     *
     * The fallback in insert_shields() finds a row where it expected none. The cheap wrong move is
     * to overwrite it - which on this table means replacing a grade somebody earned. What it must
     * do is flag the row and leave every other column alone.
     */
    public function test_losing_the_race_on_a_graded_row_keeps_the_grade(): void {
        global $DB;

        // Module first, student after, so the row genuinely has to be inserted - see the ordering
        // note in the test above.
        $fresh = $this->getDataGenerator()->create_module('assign',
            ['course' => $this->course->id, 'name' => 'Fresh graded', 'grade' => 100]);
        $item = $this->item_for('assign', (int) $fresh->id);
        $latecomer = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        // Somebody else writes the row mid-flight, and it carries a grade: this is a teacher who
        // graded the student between our read and our write, which is exactly the sequence that
        // makes overwriting instead of flagging destructive.
        racing_grade_isolation_service::$loser = (int) $latecomer->id;
        racing_grade_isolation_service::$injected = 0;
        racing_grade_isolation_service::exclude_all_but($item, [(int) $latecomer->id], null);
        $this->assertSame(1, racing_grade_isolation_service::$injected,
            'the race has to have actually been lost');

        $row = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $latecomer->id]);
        $this->assertTrue((bool) $row->excluded, 'the row somebody else wrote got flagged');

        // Now the grade lands on that same row, and a second pass must leave it alone.
        $DB->set_field('grade_grades', 'finalgrade', 77, ['id' => $row->id]);
        $DB->set_field('grade_grades', 'excluded', 0, ['id' => $row->id]);

        racing_grade_isolation_service::$loser = 0;
        racing_grade_isolation_service::exclude_all_but($item, [(int) $latecomer->id], null);

        $row = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $latecomer->id]);
        $this->assertTrue((bool) $row->excluded, 'the row is shielded');
        $this->assertEquals(77, $row->finalgrade, 'and the grade somebody earned is still there');
    }

    /**
     * A student who already has a row - graded or not - is flagged, never overwritten.
     *
     * This is the branch a real race lands in most of the time: core wrote the empty row for them
     * during a regrade, and the shield has to adopt it rather than try to insert over it.
     */
    public function test_an_existing_row_is_flagged_and_its_grade_kept(): void {
        global $DB;

        $item = $this->item_for('assign', (int) $this->ai->id);
        // setUp() already graded the recipient 50 on this activity, so their row exists.
        $this->assertEqualsWithDelta(50.0, (float) $DB->get_field('grade_grades', 'finalgrade',
            ['itemid' => $item->id, 'userid' => $this->target->id]), 0.01);

        grade_isolation_service::exclude_all_but($item,
            [(int) $this->bystander->id, (int) $this->target->id], null);

        $this->assertEqualsWithDelta(50.0, (float) $DB->get_field('grade_grades', 'finalgrade',
            ['itemid' => $item->id, 'userid' => $this->target->id]), 0.01,
            'excluding a grade is not a reason to erase it');
        $this->assertTrue((bool) $DB->get_field('grade_grades', 'excluded',
            ['itemid' => $item->id, 'userid' => $this->target->id]));
    }

    /**
     * An activity with nothing to grade needs no work. Catches: creating an empty category for a
     * page, folder or url.
     */
    public function test_activity_without_grade_items_is_left_alone(): void {
        global $DB;

        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $handled = grade_isolation_service::apply(
            $this->course->id, 'page', (int) $page->id, grade_isolation_service::MODE_OWN
        );

        $this->assertSame(0, $handled);
        $this->assertSame(1, $DB->count_records('grade_categories', ['courseid' => $this->course->id]));
    }

    /**
     * Extra credit on the AI column must not dissolve the per-student shield.
     *
     * Found on screen by the user, 2026-09-02, and it is the same field under two names: the
     * "Actuar como puntos extra" checkbox writes grade_item::aggregationcoef, and under Natural a
     * coef of 1 means extra credit - which is why the abandoned first design, that set coef = 1 on
     * every AI item, took the course maximum from 410 to 310 without anybody asking it to.
     *
     * The question this answers is the one the 27-cell matrix never asks: that matrix leaves
     * aggregationcoef at 0 for every cell except weighted mean, so extra credit is untested
     * territory. Two properties are at stake and both are measured here - the bystander keeps
     * their grade, AND the recipient's own grade still lands, because an item excluded for
     * everybody and extra credit for the one person left would be a column that counts for nobody
     * while claiming to count for its student.
     *
     * @covers \local_coursedynamicrules\local\service\grade_isolation_service::exclude_all_but
     */
    public function test_extra_credit_does_not_dissolve_the_shield(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $item = $this->item_for('assign', (int) $this->ai->id);

        // Extra credit under Natural: the item's maximum stops adding to the course maximum, and
        // whatever is scored on it is added on top instead.
        $item->aggregationcoef = 1;
        $item->update();

        grade_isolation_service::apply($this->course->id, 'assign', (int) $this->ai->id,
            grade_isolation_service::MODE_OWN, (int) $this->target->id);

        // The dangerous setting, as everywhere else in this file: empty grades counting as zero is
        // the only configuration where protection is a question at all.
        $this->configure_root(GRADE_AGGREGATE_SUM, 0);

        $this->assertEqualsWithDelta(80.0, (float) $this->percentage((int) $this->bystander->id), 0.05,
            'extra credit on the AI column must not reach the student it was hidden from');
        $this->assertNotNull($this->percentage((int) $this->target->id),
            'and the recipient must still have a total at all');
    }

    /**
     * Measure every (mode x aggregation) cell against the real gradebook.
     *
     * The bystander had 80%. Building an actual course per cell and reading their course total is
     * the only claim worth making here: if the exclusion is ever weakened - written for the wrong
     * users, or undone by a regrade - a cell stops reporting 80% and this goes red.
     *
     * Three scenarios, not two modes, and the reason is that half of this matrix used to prove
     * nothing. Asking for a grade of 0 and getting no grade item means there is no column, so the
     * bystander is safe whether or not exclude_all_but() exists at all: delete the whole mechanism
     * and nine cells stay green. That is a true measurement of the compliant path and a worthless
     * one of the shield, so the scenario a shield exists FOR is now measured too - the module that
     * ignores the request and creates its column anyway, which is not hypothetical: scorm reads
     * grademethod and maxgrade and never looks at `grade` (mod/scorm/lib.php:677-689).
     *
     * Measured, by making exclude_all_but() return 0 and re-running: 14 of the 27 cells go red -
     * seven aggregations in each of the two scenarios where the column exists. Four are
     * structurally immune, named rather than implied: under Mas alta and Moda an extra zero cannot
     * drag a maximum or a modal value below 80, and no weighting changes that. The remaining nine
     * are the compliant path, where there is no column to isolate in the first place.
     *
     * That count is the point of the restructure. The previous shape had 9 of 18 discriminating,
     * and one of the nine that did not was Media ponderada under "solo suya" - blind because the
     * AI item was left at aggregationcoef 0, which weighted mean skips outright. Giving that item
     * a weight is what turned the cell into a measurement.
     *
     * What it does NOT cover: the item sitting inside a grade category. bystander_is_safe_under()
     * never builds one, and the plugin no longer defends against that - it is Moodle's own
     * behaviour for any excluded grade filed into a category, with or without this plugin.
     *
     * It replaced a test that compared a function against a hardcoded copy of its own body, and
     * then a version that compared the measurement against a function returning a constant. Both
     * were tautologies. This asserts the measurement itself.
     */
    public function test_protection_matrix_matches_the_gradebook(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $aggregations = [
            'Natural' => GRADE_AGGREGATE_SUM,
            'Media ponderada' => GRADE_AGGREGATE_WEIGHTED_MEAN,
            'Ponderada simple' => GRADE_AGGREGATE_WEIGHTED_MEAN2,
            'Media con extra' => GRADE_AGGREGATE_EXTRACREDIT_MEAN,
            'Media' => GRADE_AGGREGATE_MEAN,
            'Mediana' => GRADE_AGGREGATE_MEDIAN,
            'Mas baja' => GRADE_AGGREGATE_MIN,
            'Mas alta' => GRADE_AGGREGATE_MAX,
            'Moda' => GRADE_AGGREGATE_MODE,
        ];
        // [mode, does the module honour the request to be ungraded]. The third row is the one
        // that measures the shield: mode nograde, module ignored it, column exists anyway.
        $scenarios = [
            'sin nota / obedece' => [grade_isolation_service::MODE_NOGRADE, true],
            'sin nota / ignora' => [grade_isolation_service::MODE_NOGRADE, false],
            'solo suya' => [grade_isolation_service::MODE_OWN, false],
        ];
        $this->assertSame(
            [grade_isolation_service::MODE_NOGRADE, grade_isolation_service::MODE_OWN],
            grade_isolation_service::modes(),
            'a new mode must be added to this matrix, not silently left unmeasured'
        );

        $report = "\n### matriz medida: el NO destinatario conserva su 80%?\n";
        $mismatches = [];

        foreach ($scenarios as $name => [$mode, $complies]) {
            foreach ($aggregations as $label => $aggregation) {
                $safe = $this->bystander_is_safe_under($mode, $aggregation, $complies);

                $report .= sprintf("  %-20s %-17s conserva su 80%%: %s\n",
                    $name, $label, $safe ? 'si' : 'NO  <-- PIERDE');

                if (!$safe) {
                    $mismatches[] = "{$name} / {$label}";
                }
            }
        }
        if ($mismatches) {
            // Only on failure. A suite that prints a table every green run trains people to skim
            // past its output, which is exactly when a real mismatch scrolls by unread.
            fwrite(STDERR, $report . "\n");
        }

        $this->assertSame([], $mismatches,
            'el espectador conserva su nota en cada modo y con cada forma de agregar');
    }

    /**
     * Build a throwaway course for one cell and report whether the bystander kept their grade.
     *
     * Uses the dangerous setting - empty grades counting as zero - because that is the only
     * configuration where protection is a question at all.
     *
     * @param string $mode
     * @param int $aggregation
     * @param bool $complies Whether the module honours the request to be created ungraded. False
     *      reproduces scorm, which ignores `grade` entirely and builds its column regardless.
     * @return bool
     */
    private function bystander_is_safe_under(string $mode, int $aggregation, bool $complies): bool {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $target = $gen->create_and_enrol($course, 'student');
        $bystander = $gen->create_and_enrol($course, 'student');

        $base = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        // What prepare_payload() asks for, and then whether the module does as it is told. A
        // module that complies produces no grade item; one that ignores `grade` produces the
        // column anyway, which is where the shield is the only thing standing between the
        // bystander and a zero.
        $asked = (int) grade_isolation_service::prepare_payload(['grade' => 100], $mode)['grade'];
        $aigrade = $complies ? $asked : 100;
        $ai = $gen->create_module('assign', ['course' => $course->id, 'grade' => $aigrade]);

        $baseitems = grade_item::fetch_all([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $base->id,
        ]);
        $baseitem = reset($baseitems);

        // Weighted mean skips every item whose aggregationcoef is <= 0 (grade_category.php:1133),
        // and a fixture that leaves them all at zero produces no total for anybody - it would
        // measure "nobody has a grade", not "the bystander lost points". A real course using this
        // method has weights, so the baseline gets one.
        if ($aggregation === GRADE_AGGREGATE_WEIGHTED_MEAN) {
            $baseitem->aggregationcoef = 1;
            $baseitem->update();
        }

        $baseitem->update_final_grade($target->id, 80);
        $baseitem->update_final_grade($bystander->id, 80);

        $aiitems = grade_isolation_service::gradable_items($course->id, 'assign', (int) $ai->id);
        if ($aiitems) {
            $aiitem = reset($aiitems);

            // The AI column needs a weight of its own, for the same reason the baseline does and
            // with sharper consequences: weighted mean skips any item whose aggregationcoef is <=
            // 0 (grade_category.php:1133), so leaving this at zero makes the cell pass no matter
            // what the shield does - the column is ignored either way. With a weight, an
            // unshielded bystander drops to (80 + 0) / 2 = 40% and the cell can actually fail.
            if ($aggregation === GRADE_AGGREGATE_WEIGHTED_MEAN) {
                $aiitem->aggregationcoef = 1;
                $aiitem->update();
            }

            $aiitem->update_final_grade($target->id, 50);
        }

        grade_isolation_service::apply($course->id, 'assign', (int) $ai->id, $mode, (int) $target->id);

        $root = grade_category::fetch_course_category($course->id);
        $root->aggregation = $aggregation;
        $root->aggregateonlygraded = 0;
        $root->update();
        grade_regrade_final_grades($course->id);

        $courseitem = grade_item::fetch_course_item($course->id);
        $gg = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $bystander->id]);
        if (!$gg || $gg->finalgrade === null) {
            return false;
        }
        $max = (float) $gg->get_grade_max();
        $pct = $max > 0 ? round((float) $gg->finalgrade / $max * 100, 1) : 0.0;

        return abs($pct - 80.0) < 0.05;
    }
}
