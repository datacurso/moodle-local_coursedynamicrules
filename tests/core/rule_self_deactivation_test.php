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

namespace local_coursedynamicrules\core;

/**
 * set_active() records the moment the ENGINE switches a rule off, so the badge can tell that state
 * apart from a manual pause.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\core\rule::set_active
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_self_deactivation_test extends \advanced_testcase {
    /**
     * Insert an active, sealed rule and return a rule instance for it.
     *
     * @return array{0: rule, 1: int} The rule instance and its id.
     */
    private function active_rule(): array {
        global $DB;

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $record = (object) [
            'courseid' => $courseid,
            'name' => 'Self-deactivation probe',
            'description' => '',
            'active' => 1,
            'timeactivated' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = (int) $DB->insert_record('local_coursedynamicrules_rule', $record);

        return [new rule($record, []), $record->id];
    }

    /**
     * The engine switching a rule off stamps timeautodeactivated.
     */
    public function test_engine_deactivation_stamps_the_moment(): void {
        global $DB;
        $this->resetAfterTest(true);

        [$rule, $ruleid] = $this->active_rule();

        $rule->set_active(false);

        $row = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(0, $row->active);
        $this->assertNotNull($row->timeautodeactivated, 'Engine deactivation must record the moment.');
        $this->assertGreaterThan(0, (int) $row->timeautodeactivated);
    }

    /**
     * Reactivating clears the stamp, so a later manual pause never reads as a stale "executed".
     */
    public function test_reactivation_clears_the_stamp(): void {
        global $DB;
        $this->resetAfterTest(true);

        [$rule, $ruleid] = $this->active_rule();

        $rule->set_active(false);
        $rule->set_active(true);

        $row = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->active);
        $this->assertNull($row->timeautodeactivated, 'Reactivation must clear the engine self-deactivation stamp.');
    }
}
