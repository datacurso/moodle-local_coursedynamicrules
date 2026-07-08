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
     * Create a condition instance not yet persisted, as the add-condition flow does.
     *
     * @return grade_in_activity_condition
     */
    private function create_test_condition(): grade_in_activity_condition {
        $record = new stdClass();
        $record->ruleid = 1;
        $record->conditiontype = 'grade_in_activity';
        $record->params = json_encode([]);

        return new grade_in_activity_condition($record, 1);
    }

    /**
     * A grade threshold of 0 is a legitimate value and must be persisted.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_preserves_zero_value(): void {
        global $DB;

        $this->resetAfterTest(true);

        $condition = $this->create_test_condition();

        $formdata = new stdClass();
        $formdata->ruleid = 1;
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

        $record = $DB->get_record('local_coursedynamicrules_condition', ['ruleid' => 1], '*', MUST_EXIST);
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

        $condition = $this->create_test_condition();

        $formdata = new stdClass();
        $formdata->ruleid = 1;
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

        $record = $DB->get_record('local_coursedynamicrules_condition', ['ruleid' => 1], '*', MUST_EXIST);
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

        $condition = $this->create_test_condition();

        $formdata = new stdClass();
        $formdata->ruleid = 1;
        $formdata->cmid = 42;
        $formdata->gradeitems = '2517';

        try {
            $condition->save_condition($formdata);
            $this->fail('Expected invalid_parameter_exception was not thrown');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition'));
        }
    }
}
