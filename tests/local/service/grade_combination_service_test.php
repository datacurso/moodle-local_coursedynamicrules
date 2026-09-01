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

use grade_grade;
use grade_item;

/**
 * Tests for carrying a reinforcement grade back onto the activity it recovers.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\service\grade_combination_service
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_combination_service_test extends \advanced_testcase {
    /**
     * The arithmetic of every mode and rule, in the source activity's scale.
     *
     * Pure function, so every policy the form offers is pinned here with the exact numbers a
     * teacher would predict. Catches: a rule silently behaving like another one.
     *
     * @param string $mode
     * @param string $rule
     * @param float|null $source
     * @param float $new
     * @param float|null $pass
     * @param float|null $expected
     * @dataProvider compute_provider
     */
    public function test_compute(string $mode, string $rule, ?float $source, float $new,
            ?float $pass, ?float $expected): void {
        $this->assertSame(
            $expected,
            grade_combination_service::compute($mode, $rule, $source, $new, $pass)
        );
    }

    /**
     * Every combination the form can produce.
     *
     * @return array
     */
    public static function compute_provider(): array {
        $combine = grade_isolation_service::MODE_COMBINE;
        $replace = grade_isolation_service::MODE_REPLACE;

        return [
            // Combine, keep the best: never leaves the student worse off.
            'combinar mejor, refuerzo sube' => [$combine, 'best', 40.0, 90.0, null, 90.0],
            'combinar mejor, refuerzo baja' => [$combine, 'best', 40.0, 20.0, null, 40.0],
            // Combine, average: recognises the effort but keeps the weight of the original error.
            'combinar promedio, refuerzo sube' => [$combine, 'mean', 40.0, 90.0, null, 65.0],
            'combinar promedio, refuerzo baja' => [$combine, 'mean', 40.0, 20.0, null, 30.0],
            // Replace always: literal substitution, worse included.
            'reemplazar siempre, peor' => [$replace, 'always', 40.0, 20.0, null, 20.0],
            'reemplazar siempre, mejor' => [$replace, 'always', 40.0, 90.0, null, 90.0],
            // Replace only when it improves.
            'reemplazar si mejora, mejor' => [$replace, 'improve', 40.0, 90.0, null, 90.0],
            'reemplazar si mejora, peor' => [$replace, 'improve', 40.0, 20.0, null, 40.0],
            // Replace capped at the pass mark: you recover, you do not top the class.
            'reemplazar con tope, por encima' => [$replace, 'cap', 40.0, 90.0, 60.0, 60.0],
            'reemplazar con tope, por debajo' => [$replace, 'cap', 40.0, 45.0, 60.0, 45.0],
            'reemplazar con tope, peor que el original' => [$replace, 'cap', 40.0, 20.0, 60.0, 40.0],
            'reemplazar con tope sin nota de aprobacion' => [$replace, 'cap', 40.0, 90.0, null, 90.0],
            // A student with no original grade is treated as zero, never as "skip".
            'sin nota original, combinar mejor' => [$combine, 'best', null, 70.0, null, 70.0],
            'sin nota original, combinar promedio' => [$combine, 'mean', null, 70.0, null, 35.0],
            // Modes that never touch the source produce nothing.
            'sin nota no calcula' => [grade_isolation_service::MODE_NOGRADE, '', 40.0, 90.0, null, null],
            'nota propia no calcula' => [grade_isolation_service::MODE_OWN, '', 40.0, 90.0, null, null],
        ];
    }

    /**
     * End to end: the reinforcement is graded and the source activity ends up with the combined
     * result, scaled across two different maximum grades.
     *
     * Catches: comparing raw points from activities on different scales, which would make a 45/50
     * reinforcement look worse than a 50/100 original.
     */
    public function test_handle_graded_writes_the_scaled_result_onto_the_source(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');

        // Source out of 100, reinforcement out of 50: the scales deliberately differ.
        $source = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Examen', 'grade' => 100]);
        $reinforcement = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Refuerzo', 'grade' => 50]);

        $sourceitem = grade_combination_service::primary_item((int) $source->cmid);
        $sourceitem->update_final_grade($student->id, 40);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id, 'name' => 'R', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        grade_combination_service::record_generation(
            (int) $course->id, (int) $ruleid, 1, (int) $student->id,
            (int) $reinforcement->cmid, (int) $source->cmid,
            grade_isolation_service::MODE_COMBINE, grade_isolation_service::RULE_BEST
        );

        // 45 out of 50 is 90%, which against the source's 100 is 90 - better than the original 40.
        grade_combination_service::primary_item((int) $reinforcement->cmid)
            ->update_final_grade($student->id, 45);

        $this->assertTrue(grade_combination_service::handle_graded((int) $reinforcement->cmid, (int) $student->id));

        $final = grade_grade::fetch(['itemid' => $sourceitem->id, 'userid' => $student->id]);
        $this->assertEqualsWithDelta(90.0, (float) $final->finalgrade, 0.05,
            'the reinforcement is read as a proportion of its own scale, not as raw points');
    }

    /**
     * The frozen original survives a re-grade of the reinforcement.
     *
     * Catches: reading the source grade live, which after the first write would compare the new
     * attempt against the already-improved value and lose the student's real original mark.
     */
    public function test_the_original_grade_is_frozen_against_regrading(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/gradelib.php');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $source = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $reinforcement = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $sourceitem = grade_combination_service::primary_item((int) $source->cmid);
        $sourceitem->update_final_grade($student->id, 40);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id, 'name' => 'R', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        grade_combination_service::record_generation(
            (int) $course->id, (int) $ruleid, 1, (int) $student->id,
            (int) $reinforcement->cmid, (int) $source->cmid,
            grade_isolation_service::MODE_REPLACE, grade_isolation_service::RULE_ALWAYS
        );

        $newitem = grade_combination_service::primary_item((int) $reinforcement->cmid);

        // First grading pushes the source to 90.
        $newitem->update_final_grade($student->id, 90);
        grade_combination_service::handle_graded((int) $reinforcement->cmid, (int) $student->id);
        $this->assertEqualsWithDelta(90.0,
            (float) grade_grade::fetch(['itemid' => $sourceitem->id, 'userid' => $student->id])->finalgrade, 0.05);

        // The teacher re-grades the reinforcement down to 30. "Always" means the source follows it
        // down - and crucially it must land on 30, not on some value derived from the 90 already
        // written onto the source.
        $newitem->update_final_grade($student->id, 30);
        grade_combination_service::handle_graded((int) $reinforcement->cmid, (int) $student->id);
        $this->assertEqualsWithDelta(30.0,
            (float) grade_grade::fetch(['itemid' => $sourceitem->id, 'userid' => $student->id])->finalgrade, 0.05);

        $link = $DB->get_record(grade_combination_service::TABLE, ['cmid' => $reinforcement->cmid]);
        $this->assertEqualsWithDelta(40.0, (float) $link->sourcegrade, 0.05,
            'the stored original is still the real original');
    }

    /**
     * A grade on an activity the plugin never generated is ignored, which is also what keeps the
     * observer from looping when it writes onto the source.
     */
    public function test_untracked_activity_is_ignored(): void {
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $other = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $this->assertFalse(grade_combination_service::handle_graded((int) $other->cmid, (int) $student->id));
    }

    /**
     * The source activity is taken from the rule's own grade condition, so the teacher never names
     * it twice and it can never disagree with what triggers the rule.
     */
    public function test_source_is_resolved_from_the_rule_condition(): void {
        global $DB;
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $quiz = $gen->create_module('quiz', ['course' => $course->id]);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id, 'name' => 'R', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertNull(grade_combination_service::resolve_source_cmid((int) $ruleid),
            'a rule with no grade condition names no source');

        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'grade_in_activity',
            'params' => json_encode(['cmid' => (int) $quiz->cmid]),
        ]);

        $this->assertSame((int) $quiz->cmid, grade_combination_service::resolve_source_cmid((int) $ruleid));
    }

    /**
     * Every mode records a row, because the row is what stops the activity being generated again
     * on the next scheduled pass. Modes that carry nothing store no source.
     *
     * Catches: reverting to "only combine and replace are recorded", which would leave the
     * runaway open for the default mode - the one almost everybody will use.
     */
    public function test_every_mode_records_a_row_and_only_some_carry_a_source(): void {
        global $DB;
        $this->resetAfterTest(true);

        $expected = [
            grade_isolation_service::MODE_NOGRADE => 0,
            grade_isolation_service::MODE_OWN => 0,
            grade_isolation_service::MODE_COMBINE => 77,
            grade_isolation_service::MODE_REPLACE => 77,
        ];

        $userid = 0;
        foreach ($expected as $mode => $wantsource) {
            $userid++;
            $id = grade_combination_service::record_generation(1, 1, 1, $userid, 500 + $userid, 77, $mode, '');
            $this->assertGreaterThan(0, $id, "el modo {$mode} debe dejar marca");
            $row = $DB->get_record(grade_combination_service::TABLE, ['id' => $id]);
            $this->assertSame($wantsource, (int) $row->sourcecmid);
        }

        $this->assertSame(4, $DB->count_records(grade_combination_service::TABLE));
    }

    /**
     * The guard that closes the runaway: once an action has generated for a student, it must
     * never generate again.
     *
     * Catches: the scheduled tasks re-running forever. no_complete_activity_task fires every
     * minute and its gate is a fixed date, so without this a single rule produces up to 1440
     * activities - and 1440 paid AI calls - per student per day.
     */
    public function test_already_generated_is_scoped_to_the_action_and_the_student(): void {
        $this->resetAfterTest(true);

        $this->assertFalse(grade_combination_service::already_generated(10, 99));

        grade_combination_service::record_generation(
            1, 1, 10, 99, 500, 0, grade_isolation_service::MODE_NOGRADE, ''
        );

        $this->assertTrue(grade_combination_service::already_generated(10, 99));
        $this->assertFalse(grade_combination_service::already_generated(10, 100),
            'otro estudiante de la misma accion todavia no la recibio');
        $this->assertFalse(grade_combination_service::already_generated(11, 99),
            'otra accion sobre el mismo estudiante es una decision distinta del docente');
    }

    /**
     * A marker row carries nothing, so a grade on it writes nowhere.
     */
    public function test_a_marker_row_without_a_source_carries_nothing(): void {
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $activity = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        grade_combination_service::record_generation(
            (int) $course->id, 1, 1, (int) $student->id, (int) $activity->cmid, 0,
            grade_isolation_service::MODE_NOGRADE, ''
        );

        $this->assertFalse(
            grade_combination_service::handle_graded((int) $activity->cmid, (int) $student->id)
        );
    }
}
