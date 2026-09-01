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

use grade_category;
use grade_grade;
use grade_item;

/**
 * GAP PROBE (throwaway): attacks the blind spots of the risk register.
 *
 * Probes whether (a) severity depends on the aggregation method, not only on the
 * "exclude empty grades" flag, (b) checking only the course root category is enough,
 * (c) a teacher's manual weights survive a new item appearing.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class gradebook_gap_probe_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $target;
    /** @var \stdClass */
    private $bystander;
    /** @var grade_item */
    private $baseitem;
    /** @var grade_item */
    private $aiitem;

    /**
     * Two students, a baseline assign (both 80/100) and an "AI" assign (only target, 50/100).
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

        $base = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Baseline', 'grade' => 100]);
        $ai = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'AI reinforcement', 'grade' => 100]);

        $this->baseitem = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $base->id]);
        $this->aiitem = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $ai->id]);

        $this->baseitem->update_final_grade($this->target->id, 80);
        $this->baseitem->update_final_grade($this->bystander->id, 80);
        $this->aiitem->update_final_grade($this->target->id, 50);
    }

    /**
     * Write a line to STDERR (visible even when the test passes).
     *
     * @param string $line
     */
    private function say(string $line): void {
        fwrite(STDERR, $line . "\n");
    }

    /**
     * Course total of a user as [finalgrade, per-user max, percentage].
     *
     * @param int $userid
     * @return array
     */
    private function total(int $userid): array {
        $courseitem = grade_item::fetch_course_item($this->course->id);
        $gg = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $userid]);
        if (!$gg || $gg->finalgrade === null) {
            return [null, null, null];
        }
        $max = (float) $gg->get_grade_max();
        $grade = (float) $gg->finalgrade;
        return [$grade, $max, $max > 0 ? round($grade / $max * 100, 1) : 0.0];
    }

    /**
     * Set the root category aggregation and empty-grade flag, then regrade.
     *
     * @param int $aggregation
     * @param int $onlygraded
     */
    private function configure(int $aggregation, int $onlygraded): void {
        $cat = grade_category::fetch_course_category($this->course->id);
        $cat->aggregation = $aggregation;
        $cat->aggregateonlygraded = $onlygraded;
        $cat->update();
        grade_regrade_final_grades($this->course->id);
    }

    /**
     * GAP 1: is "exclude empty grades" the whole story, or does the aggregation method
     * change how bad the damage is? Probes every aggregation Moodle 4.5 ships.
     */
    public function test_gap_severity_depends_on_aggregation_method(): void {
        $methods = [
            'Natural (suma)' => GRADE_AGGREGATE_SUM,
            'Media de calificaciones' => GRADE_AGGREGATE_MEAN,
            'Media ponderada' => GRADE_AGGREGATE_WEIGHTED_MEAN,
            'Media ponderada simple' => GRADE_AGGREGATE_WEIGHTED_MEAN2,
            'Media con creditos extra' => GRADE_AGGREGATE_EXTRACREDIT_MEAN,
            'Mediana' => GRADE_AGGREGATE_MEDIAN,
            'Calificacion mas baja' => GRADE_AGGREGATE_MIN,
            'Calificacion mas alta' => GRADE_AGGREGATE_MAX,
            'Moda' => GRADE_AGGREGATE_MODE,
        ];

        $this->say("\n### GAP 1 - total del NO destinatario (base 80/100, sin la actividad IA)");
        $this->say(str_pad('Agregacion', 28) . ' | excluir=SI      | excluir=NO      | dano');

        $findings = [];
        foreach ($methods as $label => $agg) {
            $this->configure($agg, 1);
            [$gon, $mon, $pon] = $this->total($this->bystander->id);
            $this->configure($agg, 0);
            [$goff, $moff, $poff] = $this->total($this->bystander->id);

            $fmt = function ($g, $m, $p) {
                return $g === null ? 'sin nota' : sprintf('%s/%s = %s%%', rtrim(rtrim(number_format($g, 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.'), $p);
            };
            $drop = ($pon !== null && $poff !== null) ? round($pon - $poff, 1) : null;
            $damage = $drop === null ? '?' : ($drop <= 0.05 ? 'ninguno' : "-{$drop} puntos porcentuales");

            $this->say(str_pad($label, 28) . ' | ' . str_pad($fmt($gon, $mon, $pon), 15)
                . ' | ' . str_pad($fmt($goff, $moff, $poff), 15) . ' | ' . $damage);
            $findings[$label] = $drop;
        }

        // The point of the probe: the damage is NOT uniform across aggregations.
        $this->assertNotEmpty($findings);
        $this->say('');
    }

    /**
     * GAP 2: the register checks only the course ROOT category (depth = 1). If the item lives in a
     * subcategory whose own flag differs, is the root's flag still the answer?
     *
     * The subcategory must hold at least one item the student IS graded on: grade_category.php:726
     * computes $allnull BEFORE the zero-filling loop, so a category where the student has no grade
     * at all yields a null total regardless of the flag (a short-circuit that masks the effect).
     *
     * @param int $subflag aggregateonlygraded for the subcategory.
     * @param string $label Human label for the output.
     * @dataProvider subcategory_flag_provider
     */
    public function test_gap_subcategory_flag_beats_root_flag(int $subflag, string $label): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $extra = $gen->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Refuerzo previo', 'grade' => 100,
        ]);
        $extraitem = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $extra->id]);

        // Subcategory holding the AI activity AND an activity both students are graded on.
        $sub = new grade_category(['courseid' => $this->course->id, 'fullname' => 'Refuerzos'], false);
        $sub->insert();
        foreach ([$this->aiitem, $extraitem] as $item) {
            $item->categoryid = $sub->id;
            $item->update();
        }
        $extraitem->update_final_grade($this->target->id, 80);
        $extraitem->update_final_grade($this->bystander->id, 80);

        // Root: exclude empty grades ON - the "safe" configuration the current detection reports.
        $root = grade_category::fetch_course_category($this->course->id);
        $root->aggregation = GRADE_AGGREGATE_SUM;
        $root->aggregateonlygraded = 1;
        $root->update();

        // Subcategory: the flag the current detection never looks at.
        $sub = grade_category::fetch(['id' => $sub->id]);
        $sub->aggregation = GRADE_AGGREGATE_SUM;
        $sub->aggregateonlygraded = $subflag;
        $sub->update();
        grade_regrade_final_grades($this->course->id);

        [$g, $m, $p] = $this->total($this->bystander->id);
        $rootflag = (int) $DB->get_field('grade_categories', 'aggregateonlygraded',
            ['courseid' => $this->course->id, 'depth' => 1]);

        $this->say("### GAP 2 - item IA en subcategoria, {$label}");
        $this->say("  Deteccion actual (root depth=1): aggregateonlygraded={$rootflag} => reportaria 'sin riesgo'");
        $this->say("  Total real del NO destinatario:  {$g}/{$m} = {$p}%");

        $this->assertSame(1, $rootflag, 'la raiz siempre dice "seguro" en esta sonda');
    }

    /**
     * Subcategory flag scenarios.
     *
     * @return array
     */
    public static function subcategory_flag_provider(): array {
        return [
            'subcategoria excluir=SI' => [1, 'subcategoria excluir=SI'],
            'subcategoria excluir=NO' => [0, 'subcategoria excluir=NO'],
        ];
    }

    /**
     * GAP 5: "Media ponderada" needs explicit weights; without them nothing aggregates. Measure it
     * with weights actually set, which is how a real course using that method looks.
     */
    public function test_gap_weighted_mean_with_real_weights(): void {
        foreach ([$this->baseitem, $this->aiitem] as $item) {
            $item->aggregationcoef = 1;
            $item->update();
        }

        $this->say("\n### GAP 5 - Media ponderada con pesos reales (1 y 1)");
        foreach ([1, 0] as $flag) {
            $this->configure(GRADE_AGGREGATE_WEIGHTED_MEAN, $flag);
            [$g, $m, $p] = $this->total($this->bystander->id);
            $shown = $g === null ? 'sin nota' : "{$g}/{$m} = {$p}%";
            $this->say("  excluir=" . ($flag ? 'SI' : 'NO') . ": NO destinatario {$shown}");
        }
        $this->say('');
        $this->assertTrue(true);
    }

    /**
     * GAP 3: the teacher manually pinned weights so they add up to 100%. What does a new
     * item do to that configuration?
     */
    public function test_gap_new_item_vs_manual_weights(): void {
        $this->configure(GRADE_AGGREGATE_SUM, 1);

        // Teacher pins the baseline at 100% of the course total.
        $this->baseitem->weightoverride = 1;
        $this->baseitem->aggregationcoef2 = 1.0;
        $this->baseitem->update();
        grade_regrade_final_grades($this->course->id);

        $before = grade_item::fetch(['id' => $this->aiitem->id]);
        $this->say("\n### GAP 3 - pesos fijados a mano por el docente");
        $this->say(sprintf('Baseline: weightoverride=%d peso=%s', $this->baseitem->weightoverride,
            $this->baseitem->aggregationcoef2));
        $this->say(sprintf('Item IA:  weightoverride=%d peso=%s', $before->weightoverride, $before->aggregationcoef2));

        [$g, $m, $p] = $this->total($this->bystander->id);
        $this->say("Total del NO destinatario: {$g}/{$m} = {$p}%");
        [$g2, $m2, $p2] = $this->total($this->target->id);
        $this->say("Total del destinatario:    {$g2}/{$m2} = {$p2}%");
        $this->say('');

        $this->assertNotNull($g);
    }

    /**
     * GAP 4: how many grade items does a single generated activity really create?
     * The register assumes "one activity = one column".
     */
    public function test_gap_activities_can_create_more_than_one_column(): void {
        $gen = $this->getDataGenerator();
        $this->say("\n### GAP 4 - columnas por actividad generada");

        $probes = [
            'assign' => ['course' => $this->course->id],
            'quiz' => ['course' => $this->course->id],
            'workshop' => ['course' => $this->course->id],
            'lesson' => ['course' => $this->course->id],
            'forum' => ['course' => $this->course->id, 'grade_forum' => 100, 'scale' => 100, 'assessed' => 1],
        ];

        foreach ($probes as $modname => $opts) {
            try {
                $instance = $gen->create_module($modname, $opts);
                $items = grade_item::fetch_all([
                    'courseid' => $this->course->id,
                    'itemtype' => 'mod',
                    'itemmodule' => $modname,
                    'iteminstance' => $instance->id,
                ]) ?: [];
                $names = [];
                foreach ($items as $it) {
                    $names[] = ($it->itemname ?: '(sin nombre)') . " [itemnumber={$it->itemnumber}, max={$it->grademax}]";
                }
                $this->say(str_pad($modname, 10) . ' => ' . count($items) . ' item(s): ' . implode('; ', $names));
            } catch (\Throwable $e) {
                $this->say(str_pad($modname, 10) . ' => no se pudo generar: ' . $e->getMessage());
            }
        }
        $this->say('');
        $this->assertTrue(true);
    }
}
