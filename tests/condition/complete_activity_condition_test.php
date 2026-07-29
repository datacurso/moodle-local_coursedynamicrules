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

use local_coursedynamicrules\condition\complete_activity\complete_activity_condition;
use stdClass;

/**
 * Tests for the complete_activity condition course module validation.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\complete_activity\complete_activity_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class complete_activity_condition_test extends \advanced_testcase {
    /**
     * A missing course module must be rejected at save time, never persisted as cmid 0.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_rejects_missing_coursemodule(): void {
        global $DB;

        $this->resetAfterTest(true);

        $record = (object) ['ruleid' => 1, 'conditiontype' => 'complete_activity', 'params' => json_encode([])];
        $condition = new complete_activity_condition($record, 1);

        $formdata = (object) ['ruleid' => 1, 'coursemodule' => 0];

        try {
            $condition->save_condition($formdata);
            $this->fail('Expected invalid_parameter_exception for missing course module');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition'));
        }
    }

    /**
     * A stale cmid (module deleted) must not raise warnings; evaluate returns false.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_without_warning_for_stale_cmid(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $record = (object) [
            'ruleid' => 1,
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => 999999]),
        ];
        $condition = new complete_activity_condition($record, $course->id);

        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
        $this->assertDebuggingNotCalled();
    }

    /**
     * A stale cmid must not raise warnings in the description; it returns an empty string.
     *
     * @covers ::get_description
     */
    public function test_get_description_empty_without_warning_for_stale_cmid(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $record = (object) [
            'ruleid' => 1,
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => 999999]),
        ];
        $condition = new complete_activity_condition($record, $course->id);

        $description = $condition->get_description();

        $this->assertSame('', $description);
        $this->assertDebuggingNotCalled();
    }
}
