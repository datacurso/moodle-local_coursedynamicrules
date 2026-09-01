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

namespace local_coursedynamicrules;

use core_availability\tree;
use grade_category;
use grade_grade;
use grade_item;

/**
 * EMPIRICAL VALIDATION (throwaway): does a per-user restricted activity punch a hole in the
 * grades of the students who do NOT receive it?
 *
 * Reproduces exactly what createaiactivity_action::execute() does after the module exists
 * (availability_user restriction, visible=1, cache rebuild) and measures the course total of
 * a NON-target student under the gradebook configurations that matter.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class gradebook_hole_validation_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass target student (receives the AI activity) */
    private $target;
    /** @var \stdClass bystander student (does NOT receive it) */
    private $bystander;
    /** @var grade_item baseline activity item (both graded 80/100) */
    private $baseitem;
    /** @var grade_item AI reinforcement activity item (only target graded 50/100) */
    private $aiitem;
    /** @var int cmid of the AI activity */
    private $aicmid;

    /**
     * Build course, two students, a baseline assign and an "AI" assign restricted to the target.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->target = $gen->create_and_enrol($this->course, 'student');
        $this->bystander = $gen->create_and_enrol($this->course, 'student');

        $base = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Baseline', 'grade' => 100]);
        $ai = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'AI reinforcement', 'grade' => 100]);
        $this->aicmid = $ai->cmid;

        // --- Verbatim from createaiactivity_action::execute() lines 106-121 ---
        $availabilityoptions = (object) [
            'type' => 'user',
            'userids' => [$this->target->id],
        ];
        $availability = tree::get_root_json([$availabilityoptions], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $ai->cmid]);
        set_coursemodule_visible($ai->cmid, 1);
        rebuild_course_cache($this->course->id, true);
        // --- end verbatim ---

        $this->baseitem = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $base->id]);
        $this->aiitem = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $ai->id]);

        // Both students 80/100 on the baseline; only the target 50/100 on the AI activity.
        $this->baseitem->update_final_grade($this->target->id, 80);
        $this->baseitem->update_final_grade($this->bystander->id, 80);
        $this->aiitem->update_final_grade($this->target->id, 50);
    }

    /**
     * Configure the course category and regrade.
     *
     * @param int $aggregation GRADE_AGGREGATE_*
     * @param int $onlygraded aggregateonlygraded flag
     */
    private function configure(int $aggregation, int $onlygraded): void {
        $cat = grade_category::fetch_course_category($this->course->id);
        $cat->aggregation = $aggregation;
        $cat->aggregateonlygraded = $onlygraded;
        $cat->update();
        grade_regrade_final_grades($this->course->id);
    }

    /**
     * Course total of a user as [finalgrade, per-user max].
     *
     * @param int $userid
     * @return array
     */
    private function total(int $userid): array {
        $courseitem = grade_item::fetch_course_item($this->course->id);
        $gg = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $userid]);
        $this->assertNotFalse($gg, 'course total row must exist');
        return [(float) $gg->finalgrade, (float) $gg->get_grade_max()];
    }

    /**
     * Print a table of DB rows to STDERR (PHPUnit swallows STDOUT of passing tests).
     *
     * @param string $title
     * @param array $rows
     * @param string[] $cols
     */
    private function dump(string $title, array $rows, array $cols): void {
        $out = "\n### {$title}\n";
        $out .= implode(' | ', $cols) . "\n";
        foreach ($rows as $r) {
            $vals = [];
            foreach ($cols as $c) {
                $v = $r->$c ?? null;
                $vals[] = $v === null ? 'NULL' : (is_numeric($v) ? (string) (float) $v : (string) $v);
            }
            $out .= implode(' | ', $vals) . "\n";
        }
        fwrite(STDERR, $out);
    }

    /**
     * Dump the raw rows so the data model is visible: what a "grade item" and a "grade" really are.
     */
    public function test_dump_raw_rows(): void {
        global $DB;
        $names = [$this->target->id => 'A(target)', $this->bystander->id => 'B(bystander)'];

        $cms = $DB->get_records('course_modules', ['course' => $this->course->id], 'id');
        foreach ($cms as $cm) {
            $cm->availability = $cm->availability ? substr($cm->availability, 0, 90) : null;
        }
        $this->dump('course_modules (una fila por actividad en el curso)', $cms,
            ['id', 'course', 'module', 'instance', 'section', 'visible', 'availability']);

        $this->dump('assign (la actividad en sí; "grade" = nota máxima)', $DB->get_records('assign', ['course' => $this->course->id], 'id'),
            ['id', 'name', 'grade']);

        $this->dump('grade_categories (la raíz del curso y su configuración)', $DB->get_records('grade_categories', ['courseid' => $this->course->id]),
            ['id', 'fullname', 'aggregation', 'aggregateonlygraded', 'droplow']);

        $items = $DB->get_records('grade_items', ['courseid' => $this->course->id], 'sortorder');
        $this->dump('grade_items (una fila por columna del libro)', $items,
            ['id', 'categoryid', 'itemtype', 'itemmodule', 'iteminstance', 'itemname', 'grademax', 'aggregationcoef', 'hidden']);

        foreach ([1, 0] as $flag) {
            $this->configure(GRADE_AGGREGATE_SUM, $flag);
            $grades = $DB->get_records_sql(
                "SELECT gg.id, gg.userid, gg.itemid, gi.itemname, gi.itemtype, gg.rawgrade, gg.finalgrade,
                        gg.rawgrademax, gg.excluded, gg.overridden, gg.hidden, gg.aggregationstatus, gg.aggregationweight
                   FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid
                  WHERE gi.courseid = :c ORDER BY gg.userid, gi.sortorder", ['c' => $this->course->id]);
            foreach ($grades as $g) {
                $g->user = $names[$g->userid] ?? $g->userid;
                $g->itemname = $g->itemname ?? 'Total del curso';
            }
            $this->dump("grade_grades con aggregateonlygraded={$flag} (una fila por estudiante x columna)", $grades,
                ['id', 'user', 'itemid', 'itemname', 'rawgrade', 'finalgrade', 'rawgrademax', 'excluded', 'overridden',
                    'hidden', 'aggregationstatus', 'aggregationweight']);
        }
        $this->assertTrue(true);
    }

    /**
     * The restriction works as intended: target sees the activity, bystander does not.
     */
    public function test_restriction_hides_activity_only_for_bystander(): void {
        $this->assertTrue(get_fast_modinfo($this->course, $this->target->id)->cms[$this->aicmid]->uservisible);
        $this->assertFalse(get_fast_modinfo($this->course, $this->bystander->id)->cms[$this->aicmid]->uservisible);

        // But the grade item exists in the course gradebook regardless of who can see the activity,
        // and the bystander has NO grade row / null grade for it.
        $items = grade_item::fetch_all(['courseid' => $this->course->id, 'itemtype' => 'mod']);
        $this->assertCount(2, $items);
        $gg = grade_grade::fetch(['itemid' => $this->aiitem->id, 'userid' => $this->bystander->id]);
        $this->assertTrue($gg === false || $gg->finalgrade === null);
    }

    /**
     * Natural + "Exclude empty grades" ON (Moodle default): NO hole for the bystander.
     */
    public function test_natural_exclude_empty_on_no_hole(): void {
        $this->configure(GRADE_AGGREGATE_SUM, 1);

        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEquals(80.0, $grade, "bystander finalgrade={$grade} max={$max}");
        $this->assertEquals(100.0, $max, "bystander finalgrade={$grade} max={$max}");

        [$grade, $max] = $this->total($this->target->id);
        $this->assertEquals(130.0, $grade, "target finalgrade={$grade} max={$max}");
        $this->assertEquals(200.0, $max, "target finalgrade={$grade} max={$max}");
    }

    /**
     * Natural + "Exclude empty grades" OFF: the bystander's max doubles -> 80/200 = 40%. HOLE.
     */
    public function test_natural_exclude_empty_off_creates_hole(): void {
        $this->configure(GRADE_AGGREGATE_SUM, 0);

        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEquals(80.0, $grade, "bystander finalgrade={$grade} max={$max}");
        $this->assertEquals(200.0, $max, "bystander finalgrade={$grade} max={$max}");
    }

    /**
     * Mean of grades + OFF: bystander mean drops from 80 to 40. HOLE. With ON stays 80.
     */
    public function test_mean_exclude_empty_off_creates_hole(): void {
        $this->configure(GRADE_AGGREGATE_MEAN, 1);
        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEqualsWithDelta(80.0, $grade / $max * 100, 0.01, "ON: bystander finalgrade={$grade} max={$max}");

        $this->configure(GRADE_AGGREGATE_MEAN, 0);
        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEqualsWithDelta(40.0, $grade / $max * 100, 0.01, "OFF: bystander finalgrade={$grade} max={$max}");
    }

    /**
     * Mitigation B: per-user grade_grade.excluded=1 for the bystander neutralises the hole with OFF,
     * without touching the item (no extra-credit side effects on drop-lowest).
     */
    public function test_excluded_flag_neutralises_hole_even_with_exclude_off(): void {
        $gg = grade_grade::fetch(['itemid' => $this->aiitem->id, 'userid' => $this->bystander->id]);
        if (!$gg) {
            $gg = new grade_grade(['itemid' => $this->aiitem->id, 'userid' => $this->bystander->id], false);
            $gg->insert();
        }
        $gg->excluded = time();
        $gg->update();
        $this->aiitem->force_regrading();
        $this->configure(GRADE_AGGREGATE_SUM, 0);

        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEquals(80.0, $grade, "bystander finalgrade={$grade} max={$max}");
        $this->assertEquals(100.0, $max, "bystander finalgrade={$grade} max={$max}");

        $this->configure(GRADE_AGGREGATE_MEAN, 0);
        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEqualsWithDelta(80.0, $grade / $max * 100, 0.01, "MEAN OFF: bystander finalgrade={$grade} max={$max}");
    }

    /**
     * Mitigation A: AI item as Extra credit under Natural neutralises the hole even with OFF.
     */
    public function test_natural_extra_credit_neutralises_hole_even_with_exclude_off(): void {
        $this->aiitem->aggregationcoef = 1;
        $this->aiitem->update();
        $this->configure(GRADE_AGGREGATE_SUM, 0);

        [$grade, $max] = $this->total($this->bystander->id);
        $this->assertEquals(80.0, $grade, "bystander finalgrade={$grade} max={$max}");
        $this->assertEquals(100.0, $max, "bystander finalgrade={$grade} max={$max}");

        [$grade, $max] = $this->total($this->target->id);
        $this->assertEquals(100.0, $max, "target finalgrade={$grade} max={$max}");
        $this->assertGreaterThanOrEqual(100.0, $grade, "target finalgrade={$grade} max={$max}");
    }
}
