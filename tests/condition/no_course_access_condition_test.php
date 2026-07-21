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

use local_coursedynamicrules\condition\no_course_access\no_course_access_condition;
use stdClass;

/**
 * Tests for the no_course_access condition input validation.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\no_course_access\no_course_access_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_course_access_condition_test extends \advanced_testcase {
    /**
     * Build a condition instance from raw params.
     *
     * @param array $params Condition params.
     * @param int $courseid Course id.
     * @return no_course_access_condition
     */
    private function create_condition(array $params, int $courseid): no_course_access_condition {
        $record = new stdClass();
        $record->ruleid = 1;
        $record->conditiontype = 'no_course_access';
        $record->params = json_encode($params);

        return new no_course_access_condition($record, $courseid);
    }

    /**
     * An invalid stored period must not make the condition match a user who accessed recently.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_for_invalid_period(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // User accessed the course just now, so a valid rule would never match.
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => time(),
        ]);

        $condition = $this->create_condition(
            ['periodvalue' => '', 'periodunit' => 'days', 'nexttimeperiod' => time()],
            $course->id
        );

        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
        $this->assertDebuggingCalled();
    }

    /**
     * Data provider of invalid period values.
     *
     * @return array
     */
    public static function invalid_period_provider(): array {
        return [
            'empty' => [''],
            'non numeric' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-5'],
        ];
    }

    /**
     * Invalid period values must be rejected at save time, never persisted.
     *
     * @dataProvider invalid_period_provider
     * @covers ::save_condition
     * @param string $value Invalid period value.
     */
    public function test_save_condition_rejects_invalid_period(string $value): void {
        global $DB;

        $this->resetAfterTest(true);

        $condition = $this->create_condition([], 1);

        $formdata = (object) ['ruleid' => 1, 'periodvalue' => $value, 'periodunit' => 'days'];

        try {
            $condition->save_condition($formdata);
            $this->fail("Expected invalid_parameter_exception for period value '{$value}'");
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition'));
        }
    }

    /**
     * A valid period is persisted as an integer.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_persists_valid_period(): void {
        global $DB;

        $this->resetAfterTest(true);

        $condition = $this->create_condition([], 1);

        $formdata = (object) ['ruleid' => 1, 'periodvalue' => '30', 'periodunit' => 'days'];
        $condition->save_condition($formdata);

        $record = $DB->get_record('local_coursedynamicrules_condition', ['ruleid' => 1], '*', MUST_EXIST);
        $params = json_decode($record->params);

        $this->assertSame(30, $params->periodvalue);
        $this->assertSame('days', $params->periodunit);
    }
}
