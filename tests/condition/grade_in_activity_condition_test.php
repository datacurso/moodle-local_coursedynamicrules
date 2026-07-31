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

namespace local_coursedynamicrules\condition;

use local_coursedynamicrules\condition\grade_in_activity\grade_in_activity_condition;
use local_coursedynamicrules\core\condition;
use local_coursedynamicrules\form\conditions\grade_in_activity_form;
use stdClass;

/**
 * Tests for Grade in activity condition.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\grade_in_activity\grade_in_activity_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_in_activity_condition_test extends \advanced_testcase {
    /**
     * Insert a rule row belonging to the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a condition instance not yet persisted, as the add-condition flow does.
     *
     * @param int $ruleid Rule id the condition will belong to.
     * @param int $courseid Course id.
     * @return grade_in_activity_condition
     */
    private function create_test_condition(int $ruleid, int $courseid): grade_in_activity_condition {
        $record = new stdClass();
        $record->ruleid = $ruleid;
        $record->conditiontype = 'grade_in_activity';
        $record->params = json_encode([]);

        return new grade_in_activity_condition($record, $courseid);
    }

    /**
     * A grade threshold of 0 is a legitimate value and must be persisted.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_preserves_zero_value(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);
        $condition = $this->create_test_condition($ruleid, $course->id);

        $formdata = new stdClass();
        $formdata->ruleid = $ruleid;
        $formdata->cmid = 42;
        $formdata->gradeitems = json_encode([
            'gradelt_613' => [
                'gradeitem' => 613,
                'condition' => 'gradelt',
                'value' => '0',
                'disabled' => false,
            ],
        ]);

        $condition->save_condition($formdata);

        $record = $DB->get_record('local_coursedynamicrules_condition', ['ruleid' => $ruleid], '*', MUST_EXIST);
        $params = json_decode($record->params, true);

        $this->assertArrayHasKey('gradelt_613', $params['gradeitemsconditions']);
        $this->assertEquals(0.0, $params['gradeitemsconditions']['gradelt_613']['value']);
        $this->assertSame('gradelt', $params['gradeitemsconditions']['gradelt_613']['condition']);
    }

    /**
     * Disabled entries and entries without a value are dropped; enabled entries keep the stored shape.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_drops_disabled_and_empty_entries(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);
        $condition = $this->create_test_condition($ruleid, $course->id);

        $formdata = new stdClass();
        $formdata->ruleid = $ruleid;
        $formdata->cmid = 42;
        $formdata->gradeitems = json_encode([
            'gradegte_613' => [
                'gradeitem' => 613,
                'condition' => 'gradegte',
                'value' => '8',
                'disabled' => false,
            ],
            'gradelt_613' => [
                'gradeitem' => 613,
                'condition' => 'gradelt',
                'value' => '5',
                'disabled' => true,
            ],
            'gradegte_614' => [
                'gradeitem' => 614,
                'condition' => 'gradegte',
                'value' => '',
                'disabled' => false,
            ],
        ]);

        $condition->save_condition($formdata);

        $record = $DB->get_record('local_coursedynamicrules_condition', ['ruleid' => $ruleid], '*', MUST_EXIST);
        $params = json_decode($record->params, true);

        $this->assertSame(42, $params['cmid']);
        $this->assertCount(1, $params['gradeitemsconditions']);
        $this->assertArrayHasKey('gradegte_613', $params['gradeitemsconditions']);
        $this->assertSame(613, $params['gradeitemsconditions']['gradegte_613']['gradeitem']);
        $this->assertSame('gradegte', $params['gradeitemsconditions']['gradegte_613']['condition']);
        $this->assertEquals(8.0, $params['gradeitemsconditions']['gradegte_613']['value']);
    }

    /**
     * A gradeitems payload that is not a JSON object (e.g. the courseid) must be rejected,
     * never silently persisted as a condition that can never be met.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_rejects_non_json_object_gradeitems(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);
        $condition = $this->create_test_condition($ruleid, $course->id);

        $formdata = new stdClass();
        $formdata->ruleid = $ruleid;
        $formdata->cmid = 42;
        $formdata->gradeitems = '2517';

        try {
            $condition->save_condition($formdata);
            $this->fail('Expected invalid_parameter_exception was not thrown');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition'));
        }
    }

    /**
     * A round-trip create -> edit must persist exactly one row, same id, and the stored
     * gradeitemsconditions must reflect only the edited set: a threshold dropped during edit must
     * not resurrect on save (spec: "grade_in_activity Dynamic Preload / Dynamic region round-trips").
     * Also verifies preload_defaults() maps the stored cmid + gradeitemsconditions back onto the
     * hidden 'cmid'/'gradeitems' fields the AMD module reads for its initial dynamicForm.load() (D5).
     *
     * @covers ::save_condition
     */
    public function test_save_condition_round_trip_persists_edited_set(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid, ] = $this->create_graded_quiz();
        $ruleid = $this->create_rule($course->id);

        $condition = $this->create_test_condition($ruleid, $course->id);
        $condition->save_condition((object) [
            'ruleid' => $ruleid,
            'cmid' => $cm->id,
            'gradeitems' => json_encode([
                'gradegte_' . $gradeitemid => [
                    'gradeitem' => $gradeitemid,
                    'condition' => 'gradegte',
                    'value' => '6',
                    'disabled' => false,
                ],
                'gradelt_' . $gradeitemid => [
                    'gradeitem' => $gradeitemid,
                    'condition' => 'gradelt',
                    'value' => '9',
                    'disabled' => false,
                ],
            ]),
        ]);

        $id = $condition->get_id();
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $storedparams = json_decode($stored->params);
        $this->assertCount(2, (array) $storedparams->gradeitemsconditions);

        $reflection = new \ReflectionClass(grade_in_activity_form::class);
        $forminstance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);
        $defaults = $method->invoke($forminstance, $storedparams);
        $this->assertSame((int) $cm->id, $defaults['cmid']);
        $this->assertSame(
            json_decode(json_encode($storedparams->gradeitemsconditions), true),
            json_decode($defaults['gradeitems'], true)
        );

        // Edit: drop the "gradelt" threshold, keep only "gradegte" with a changed value.
        $editcondition = new grade_in_activity_condition($stored, $course->id);
        $editcondition->save_condition((object) [
            'ruleid' => $ruleid,
            'cmid' => $cm->id,
            'gradeitems' => json_encode([
                'gradegte_' . $gradeitemid => [
                    'gradeitem' => $gradeitemid,
                    'condition' => 'gradegte',
                    'value' => '7',
                    'disabled' => false,
                ],
                'gradelt_' . $gradeitemid => [
                    'gradeitem' => $gradeitemid,
                    'condition' => 'gradelt',
                    'value' => '9',
                    'disabled' => true,
                ],
            ]),
        ]);

        $this->assertEquals($id, $editcondition->get_id());
        $this->assertEquals(1, $DB->count_records(condition::TABLE, ['ruleid' => $ruleid]));

        $final = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $finalparams = json_decode($final->params, true);
        $this->assertCount(1, $finalparams['gradeitemsconditions']);
        $this->assertArrayHasKey('gradegte_' . $gradeitemid, $finalparams['gradeitemsconditions']);
        $this->assertArrayNotHasKey('gradelt_' . $gradeitemid, $finalparams['gradeitemsconditions']);
        $this->assertEquals(7.0, $finalparams['gradeitemsconditions']['gradegte_' . $gradeitemid]['value']);
    }

    /**
     * Build a course with a graded quiz (automatic completion + require grade) and enrol a student.
     *
     * @return array [stdClass $course, cm_info-like $cm, int $gradeitemid, stdClass $student]
     */
    private function create_graded_quiz() {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'grade' => 10,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $gradeitem = \grade_item::fetch(['iteminstance' => $quiz->id, 'itemmodule' => 'quiz', 'itemtype' => 'mod']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        return [$course, $cm, $gradeitem->id, $student];
    }

    /**
     * Build a "grade less than" condition for the given cm / grade item.
     *
     * @param int $cmid Course module id.
     * @param int $gradeitemid Grade item id.
     * @param float $value Threshold.
     * @param int $courseid Course id.
     * @return grade_in_activity_condition
     */
    private function create_gradelt_condition($cmid, $gradeitemid, $value, $courseid) {
        $record = new stdClass();
        $record->ruleid = 1;
        $record->conditiontype = 'grade_in_activity';
        $record->params = json_encode([
            'cmid' => $cmid,
            'gradeitemsconditions' => [
                'gradelt_' . $gradeitemid => ['gradeitem' => $gradeitemid, 'condition' => 'gradelt', 'value' => $value],
            ],
        ]);

        return new grade_in_activity_condition($record, $courseid);
    }

    /**
     * A user with no grade row must NOT satisfy a "grade less than" condition.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_false_when_user_has_no_grade(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid, $student] = $this->create_graded_quiz();
        $condition = $this->create_gradelt_condition($cm->id, $gradeitemid, 8, $course->id);

        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $student->id]);

        $this->assertFalse($result);
        $this->assertDebuggingNotCalled();
    }

    /**
     * A grade row with a null final grade must NOT satisfy a "grade less than" condition.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_false_when_finalgrade_is_null(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid, $student] = $this->create_graded_quiz();

        // Create an ungraded grade row (finalgrade null).
        $gradeitem = \grade_item::fetch(['id' => $gradeitemid]);
        $gradeitem->update_final_grade($student->id, null, 'gradebook');

        $condition = $this->create_gradelt_condition($cm->id, $gradeitemid, 8, $course->id);

        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $student->id]);

        $this->assertFalse($result);
        $this->assertDebuggingNotCalled();
    }

    /**
     * The description of a condition whose activity was deleted returns empty without a PHP warning.
     *
     * @covers ::get_description
     */
    public function test_get_description_empty_when_activity_deleted(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $record = new stdClass();
        $record->ruleid = 1;
        $record->conditiontype = 'grade_in_activity';
        $record->params = json_encode([
            'cmid' => 999999,
            'gradeitemsconditions' => [],
        ]);
        $condition = new grade_in_activity_condition($record, $course->id);

        $this->assertSame('', $condition->get_description());
        $this->assertDebuggingNotCalled();
    }

    /**
     * A condition that sets only one of the two thresholds still describes itself without warnings.
     *
     * The threshold keys are read dynamically off the stored params, so the absent counterpart must
     * be treated as missing rather than dereferenced. Behat surfaced this as an undefined-property
     * warning on the listing page; only the deleted-activity path was covered before, and that path
     * returns early without ever reading a threshold.
     *
     * @covers ::get_description
     */
    public function test_get_description_with_a_single_threshold_does_not_warn(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid] = $this->create_graded_quiz();
        $condition = $this->create_gradelt_condition($cm->id, $gradeitemid, 5.0, $course->id);

        $description = $condition->get_description();

        $this->assertNotSame('', $description);
        $this->assertStringNotContainsString('[[', $description);
        $this->assertDebuggingNotCalled();
    }

    /**
     * A user graded below the threshold satisfies the condition, without warnings.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_true_when_grade_below_threshold(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid, $student] = $this->create_graded_quiz();

        $gradeitem = \grade_item::fetch(['id' => $gradeitemid]);
        $gradeitem->update_final_grade($student->id, 5, 'gradebook');

        $condition = $this->create_gradelt_condition($cm->id, $gradeitemid, 8, $course->id);

        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $student->id]);

        $this->assertTrue($result);
        $this->assertDebuggingNotCalled();
    }
}
