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
     * The default mode removes the column entirely, so nobody's total can move - and unlike the
     * category modes this holds under EVERY aggregation, which is the whole reason it is default.
     *
     * Catches: falling back to a mode that leaves the column in place, and any claim that the
     * default is aggregation-independent when it is not.
     *
     * @param int $aggregation
     * @param string $label
     * @dataProvider aggregation_provider
     */
    public function test_nograde_closes_the_hole_under_every_aggregation(int $aggregation, string $label): void {
        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_NOGRADE
        );
        // The dangerous setting: empty grades counting as zero.
        $this->configure_root($aggregation, 0);

        $this->assertEqualsWithDelta(80.0, $this->percentage($this->bystander->id), 0.05,
            "bajo {$label} el estudiante que no la recibio debe quedar intacto");
        // The row survives on purpose - grade_update() disables the grade rather than deleting it,
        // so a teacher can switch grading back on. What must be gone is its participation.
        $this->assertCount(0,
            grade_isolation_service::gradable_items($this->course->id, 'assign', (int) $this->ai->id),
            'the activity no longer contributes a gradable item');
    }

    /**
     * Aggregations where the category-based modes are known NOT to protect anyone. If "no grade"
     * survives these, it is genuinely aggregation-independent.
     *
     * @return array
     */
    public static function aggregation_provider(): array {
        return [
            'Natural' => [GRADE_AGGREGATE_SUM, 'Natural'],
            'Media' => [GRADE_AGGREGATE_MEAN, 'Media'],
            'Mas baja' => [GRADE_AGGREGATE_MIN, 'Calificacion mas baja'],
        ];
    }

    /**
     * Own-grade mode: the bystander keeps their grade whatever the "exclude empty grades" box says,
     * and the recipient gains.
     *
     * Catches: the isolation silently not being applied. With the aggregationcoef line removed the
     * exclude=0 row drops to 40 and this fails.
     *
     * @param int $onlygraded
     * @dataProvider empty_grade_flag_provider
     */
    public function test_own_grade_protects_bystanders_whatever_the_empty_grade_flag(int $onlygraded): void {
        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN
        );
        $this->configure_root(GRADE_AGGREGATE_SUM, $onlygraded);

        $this->assertEqualsWithDelta(80.0, $this->percentage($this->bystander->id), 0.05);
        $this->assertEqualsWithDelta(100.0, $this->percentage($this->target->id), 0.05,
            'extra credit adds to the numerator only, so the recipient gains');
    }

    /**
     * Combine and replace put the reinforcement in a zero-weight category: it must count for
     * NOBODY, including the student who did it, because its effect lands on the source activity.
     *
     * Catches: double counting - the student getting both the reinforcement's own points and the
     * improved source grade for the same work.
     *
     * @param string $mode
     * @dataProvider source_mode_provider
     */
    public function test_source_modes_make_the_reinforcement_count_for_nobody(string $mode): void {
        grade_isolation_service::apply($this->course->id, 'assign', (int) $this->ai->id, $mode);
        $this->configure_root(GRADE_AGGREGATE_SUM, 0);

        $this->assertEqualsWithDelta(80.0, $this->percentage($this->bystander->id), 0.05);
        $this->assertEqualsWithDelta(80.0, $this->percentage($this->target->id), 0.05,
            'the reinforcement itself must not move the recipient either');
    }

    /**
     * Modes whose effect lands on the source activity.
     *
     * @return array
     */
    public static function source_mode_provider(): array {
        return [
            'combinar' => [grade_isolation_service::MODE_COMBINE],
            'reemplazar' => [grade_isolation_service::MODE_REPLACE],
        ];
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
     * Rules are normalised per mode, and modes that take none get an empty string.
     */
    public function test_rules_are_normalised_per_mode(): void {
        $this->assertSame('', grade_isolation_service::clean_rule(grade_isolation_service::MODE_NOGRADE, 'best'));
        $this->assertSame(
            grade_isolation_service::RULE_BEST,
            grade_isolation_service::clean_rule(grade_isolation_service::MODE_COMBINE, 'nonsense'),
            'an invalid rule falls back to the first valid one for that mode'
        );
        $this->assertSame(
            grade_isolation_service::RULE_MEAN,
            grade_isolation_service::clean_rule(grade_isolation_service::MODE_COMBINE, 'mean')
        );
        $this->assertSame(
            grade_isolation_service::RULE_IMPROVE,
            grade_isolation_service::clean_rule(grade_isolation_service::MODE_REPLACE, 'nonsense')
        );
        $this->assertSame(
            grade_isolation_service::RULE_CAP,
            grade_isolation_service::clean_rule(grade_isolation_service::MODE_REPLACE, 'cap')
        );
        $this->assertSame(
            grade_isolation_service::RULE_BEST,
            grade_isolation_service::clean_rule(grade_isolation_service::MODE_COMBINE, 'improve'),
            'a rule belonging to the other mode is rejected like any other invalid value'
        );
    }

    /**
     * The form asks two questions; exactly one function turns them back into a stored mode.
     *
     * Catches: the form and the validator disagreeing about what the teacher chose - the classic
     * seam bug, where each file is green and the value breaks while crossing between them.
     */
    public function test_two_level_choice_maps_to_one_mode(): void {
        $svc = grade_isolation_service::class;

        // Unticked wins over whatever the hidden destination select happens to hold.
        $this->assertSame($svc::MODE_NOGRADE, $svc::mode_from_choice(0, $svc::MODE_REPLACE));
        $this->assertSame($svc::MODE_NOGRADE, $svc::mode_from_choice('0', $svc::MODE_COMBINE));
        $this->assertSame($svc::MODE_NOGRADE, $svc::mode_from_choice(null, $svc::MODE_OWN));

        // Graded: the destination is honoured.
        $this->assertSame($svc::MODE_OWN, $svc::mode_from_choice(1, $svc::MODE_OWN));
        $this->assertSame($svc::MODE_COMBINE, $svc::mode_from_choice(1, $svc::MODE_COMBINE));
        $this->assertSame($svc::MODE_REPLACE, $svc::mode_from_choice('1', $svc::MODE_REPLACE));

        // Graded but nonsense, or "nograde" arriving as a destination it can no longer be:
        // fall back to the mildest graded option, never silently back to ungraded.
        $this->assertSame($svc::MODE_OWN, $svc::mode_from_choice(1, 'nonsense'));
        $this->assertSame($svc::MODE_OWN, $svc::mode_from_choice(1, $svc::MODE_NOGRADE));
        $this->assertSame($svc::MODE_OWN, $svc::mode_from_choice(1, null));

        $this->assertSame(
            [$svc::MODE_OWN, $svc::MODE_COMBINE, $svc::MODE_REPLACE],
            $svc::graded_modes(),
            'the destination select offers exactly the graded modes'
        );
    }

    /**
     * The category is created once per course and reused. Catches: one category per generated
     * activity, which in a course where the rule fires for everyone fills the gradebook setup.
     */
    public function test_category_is_created_once_and_reused(): void {
        global $DB;

        $second = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id, 'name' => 'AI 2', 'grade' => 100,
        ]);

        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN
        );
        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $second->id, grade_isolation_service::MODE_OWN
        );

        $this->assertSame(2, $DB->count_records('grade_categories', ['courseid' => $this->course->id]),
            'root plus exactly one plugin category');

        $catid = (int) $DB->get_field('grade_items', 'iteminstance', [
            'courseid' => $this->course->id,
            'itemtype' => 'category',
            'idnumber' => 'local_coursedynamicrules_reinforcement',
        ]);
        $this->assertNotEmpty($catid, 'the category carries a stable idnumber, not just a name');
        $this->assertSame($catid, (int) $this->item_for('assign', (int) $second->id)->categoryid);
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

        $handled = grade_isolation_service::apply(
            $this->course->id, 'forum', (int) $forum->id, grade_isolation_service::MODE_NOGRADE
        );

        $this->assertSame(count($before), $handled);
        $this->assertCount(0, grade_isolation_service::gradable_items($this->course->id, 'forum', (int) $forum->id),
            'no item of the activity is left contributing a grade');
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
     * The aggregations where weight coefficients are simply not read. Catches: promising protection
     * the category mechanism cannot deliver.
     */
    public function test_protects_under_reports_the_measured_truth(): void {
        $this->assertTrue(grade_isolation_service::protects_under(GRADE_AGGREGATE_SUM));
        $this->assertTrue(grade_isolation_service::protects_under(GRADE_AGGREGATE_WEIGHTED_MEAN2));

        foreach ([GRADE_AGGREGATE_MEAN, GRADE_AGGREGATE_MEDIAN, GRADE_AGGREGATE_MIN,
            GRADE_AGGREGATE_MAX, GRADE_AGGREGATE_MODE] as $agg) {
            $this->assertFalse(grade_isolation_service::protects_under($agg));
        }
    }

    /**
     * Under an unprotected aggregation the CATEGORY modes do not save the bystander. Recorded so
     * the limitation cannot be quietly forgotten, and so protects_under() stays honest.
     */
    public function test_own_grade_does_not_protect_under_mean(): void {
        grade_isolation_service::apply(
            $this->course->id, 'assign', (int) $this->ai->id, grade_isolation_service::MODE_OWN
        );
        $this->configure_root(GRADE_AGGREGATE_MEAN, 0);

        $this->assertFalse(grade_isolation_service::protects_under(GRADE_AGGREGATE_MEAN));
        $this->assertEqualsWithDelta(40.0, $this->percentage($this->bystander->id), 0.05,
            'measured: Mean never reads the weight, so only "no grade" is safe here');
    }
}
