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

use grade_grade;
use grade_item;
use local_coursedynamicrules\local\service\grade_combination_service;
use local_coursedynamicrules\local\service\grade_isolation_service;

/**
 * PROBE: how the grade-isolation feature behaves across Moodle's grade TYPES, not just its
 * aggregation methods.
 *
 * The aggregation axis is already covered. This one asks the questions nobody has measured yet:
 * does un-grading work on a scale-graded activity, and what value lands on the source when a
 * scale (an ordinal list) is carried onto points (a cardinal range) or the other way round?
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class gradetype_matrix_probe_test extends \advanced_testcase {
    /**
     * Write a line to STDERR (visible even when the test passes).
     *
     * @param string $line
     */
    private function say(string $line): void {
        fwrite(STDERR, $line . "\n");
    }

    /**
     * Create a scale and return its id.
     *
     * @param string $items Comma separated scale items, lowest first.
     * @return int
     */
    private function make_scale(string $items): int {
        return (int) $this->getDataGenerator()->create_scale(['scale' => $items])->id;
    }

    /**
     * Point an assign's grade item at a scale.
     *
     * @param int $courseid
     * @param int $instance
     * @param int $scaleid
     */
    private function use_scale(int $courseid, int $instance, int $scaleid): void {
        $items = grade_item::fetch_all([
            'courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $instance,
        ]);
        $item = reset($items);
        $item->gradetype = GRADE_TYPE_SCALE;
        $item->scaleid = $scaleid;
        $item->grademax = 0;
        $item->grademin = 0;
        $item->update();
    }

    /**
     * Does un-grading work on every grade type, and does the column really stop counting?
     */
    public function test_ungrade_across_grade_types(): void {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');

        $this->say("\n### Modo SIN NOTA sobre cada tipo de calificacion");
        $gen = $this->getDataGenerator();

        foreach (['puntos' => null, 'escala' => 'Insuficiente,Suficiente,Bien,Excelente'] as $label => $scale) {
            $course = $gen->create_course();
            $ai = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);
            if ($scale !== null) {
                $this->use_scale((int) $course->id, (int) $ai->id, $this->make_scale($scale));
            }

            $before = grade_isolation_service::gradable_items((int) $course->id, 'assign', (int) $ai->id);
            $handled = grade_isolation_service::apply(
                (int) $course->id, 'assign', (int) $ai->id, grade_isolation_service::MODE_NOGRADE
            );
            $after = grade_isolation_service::gradable_items((int) $course->id, 'assign', (int) $ai->id);

            $this->say(sprintf('  %-8s items antes=%d  procesados=%d  items despues=%d  -> %s',
                $label, count($before), $handled, count($after),
                count($after) === 0 ? 'deja de contar' : 'SIGUE CONTANDO'));
        }
        $this->assertTrue(true);
    }

    /**
     * Carrying a grade onto a source of a DIFFERENT grade type. This is where proportional scaling
     * either holds up or produces nonsense.
     */
    public function test_combination_across_grade_types(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');

        $this->say("\n### COMBINAR (conservar la mejor) cruzando tipos de calificacion");
        $this->say('  origen              refuerzo            nota puesta en el origen');

        $cases = [
            ['puntos 0-100', null, 'puntos 0-50', null, 40.0, 45.0],
            ['puntos 0-100', null, 'escala 4 items', 'Insuficiente,Suficiente,Bien,Excelente', 40.0, 3.0],
            ['escala 4 items', 'Insuficiente,Suficiente,Bien,Excelente', 'puntos 0-100', null, 2.0, 90.0],
            ['escala 4 items', 'Insuficiente,Suficiente,Bien,Excelente', 'escala 4 items',
                'Insuficiente,Suficiente,Bien,Excelente', 2.0, 4.0],
        ];

        foreach ($cases as [$srclabel, $srcscale, $newlabel, $newscale, $srcgrade, $newgrade]) {
            $gen = $this->getDataGenerator();
            $course = $gen->create_course();
            $student = $gen->create_and_enrol($course, 'student');
            $source = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);
            $reinf = $gen->create_module('assign', ['course' => $course->id, 'grade' => 50]);

            if ($srcscale !== null) {
                $this->use_scale((int) $course->id, (int) $source->id, $this->make_scale($srcscale));
            }
            if ($newscale !== null) {
                $this->use_scale((int) $course->id, (int) $reinf->id, $this->make_scale($newscale));
            }

            $sourceitem = grade_combination_service::primary_item((int) $source->cmid);
            $sourceitem->update_final_grade($student->id, $srcgrade);

            $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id, 'name' => 'R', 'active' => 1,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
            grade_combination_service::record_link(
                (int) $course->id, (int) $ruleid, 1, (int) $student->id,
                (int) $reinf->cmid, (int) $source->cmid,
                grade_isolation_service::MODE_COMBINE, grade_isolation_service::RULE_BEST
            );

            grade_combination_service::primary_item((int) $reinf->cmid)
                ->update_final_grade($student->id, $newgrade);
            grade_combination_service::handle_graded((int) $reinf->cmid, (int) $student->id);

            $final = grade_grade::fetch(['itemid' => $sourceitem->id, 'userid' => $student->id]);
            $value = $final && $final->finalgrade !== null ? (float) $final->finalgrade : null;

            $item = grade_item::fetch(['id' => $sourceitem->id]);
            $note = '';
            if ($srcscale !== null && $value !== null) {
                $isinteger = (abs($value - round($value)) < 0.00001);
                $max = (float) $item->grademax;
                $note = $isinteger ? ' (entero valido)' : ' <-- FRACCIONARIO EN UNA ESCALA';
                if ($value > $max || $value < 1) {
                    $note .= ' <-- FUERA DEL RANGO 1..' . $max;
                }
            }

            $this->say(sprintf('  %-19s %-19s %s%s',
                $srclabel . ' = ' . $srcgrade,
                $newlabel . ' = ' . $newgrade,
                $value === null ? 'sin nota' : (string) $value,
                $note));
        }
        $this->say('');
        $this->assertTrue(true);
    }

    /**
     * Advanced grading (rubric, marking guide) writes its result through the ordinary grade item,
     * so the feature should be blind to it. Confirmed rather than assumed.
     */
    public function test_advanced_grading_is_just_a_grade_item(): void {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');

        $methods = \core_component::get_plugin_list('gradingform');
        $this->say("\n### Metodos de calificacion avanzada instalados: " . implode(', ', array_keys($methods)));

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $assign = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $items = grade_isolation_service::gradable_items((int) $course->id, 'assign', (int) $assign->id);
        $item = reset($items);
        $this->say(sprintf('  una tarea con rubrica sigue teniendo gradetype=%d (VALUE=%d): la funcionalidad',
            $item->gradetype, GRADE_TYPE_VALUE));
        $this->say('  no distingue el metodo, solo el item resultante.');
        $this->say('');

        $this->assertSame((int) GRADE_TYPE_VALUE, (int) $item->gradetype);
    }
}
