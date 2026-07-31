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

use local_coursedynamicrules\condition\passgrade\passgrade_condition;
use local_coursedynamicrules\core\condition;
use local_coursedynamicrules\form\conditions\passgrade_form;

/**
 * Tests for the passgrade condition course module validation.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\passgrade\passgrade_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class passgrade_condition_test extends \advanced_testcase {
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
     * A round-trip create -> edit must persist exactly one row, same id, with the mutated cmid, and
     * preload_defaults() must map the stored cmid back onto the 'coursemodule' form field.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_round_trip_persists_single_row(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $ruleid = $this->create_rule($course->id);
        $cm1 = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionpassgrade' => 1]
        );
        $cm2 = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionpassgrade' => 1]
        );

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'passgrade', 'params' => json_encode([])];
        $condition = new passgrade_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'coursemodule' => $cm1->cmid]);

        $id = $condition->get_id();
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $storedparams = json_decode($stored->params);
        $this->assertSame($cm1->cmid, $storedparams->cmid);

        $reflection = new \ReflectionClass(passgrade_form::class);
        $forminstance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);
        $defaults = $method->invoke($forminstance, $storedparams);
        $this->assertSame($cm1->cmid, $defaults['coursemodule']);

        $editcondition = new passgrade_condition($stored, $course->id);
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'coursemodule' => $cm2->cmid]);

        $this->assertEquals($id, $editcondition->get_id());
        $this->assertEquals(1, $DB->count_records(condition::TABLE, ['ruleid' => $ruleid]));

        $final = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $finalparams = json_decode($final->params);
        $this->assertSame($cm2->cmid, $finalparams->cmid);
    }

    /**
     * A missing course module must be rejected at save time, never persisted as cmid 0.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_rejects_missing_coursemodule(): void {
        global $DB;

        $this->resetAfterTest(true);

        $record = (object) ['ruleid' => 1, 'conditiontype' => 'passgrade', 'params' => json_encode([])];
        $condition = new passgrade_condition($record, 1);

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

        $record = (object) ['ruleid' => 1, 'conditiontype' => 'passgrade', 'params' => json_encode(['cmid' => 999999])];
        $condition = new passgrade_condition($record, $course->id);

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

        $record = (object) ['ruleid' => 1, 'conditiontype' => 'passgrade', 'params' => json_encode(['cmid' => 999999])];
        $condition = new passgrade_condition($record, $course->id);

        $description = $condition->get_description();

        $this->assertSame('', $description);
        $this->assertDebuggingNotCalled();
    }
}
