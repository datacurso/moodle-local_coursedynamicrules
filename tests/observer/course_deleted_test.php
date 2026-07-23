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

namespace local_coursedynamicrules\observer;

/**
 * Tests that deleting a course removes its rules, conditions and actions.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\observer\course_deleted
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_deleted_test extends \advanced_testcase {
    /**
     * Deleting a course must remove its rules and their conditions and actions.
     */
    public function test_course_deletion_removes_rules(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'To be removed',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => 1]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode(['messagesubject' => 's']),
        ]);

        // Sanity: rows exist before deletion.
        $this->assertEquals(1, $DB->count_records('local_coursedynamicrules_rule', ['courseid' => $course->id]));

        delete_course($course->id, false);

        $this->assertEquals(0, $DB->count_records('local_coursedynamicrules_rule', ['courseid' => $course->id]));
        $this->assertEquals(0, $DB->count_records('local_coursedynamicrules_condition', ['ruleid' => $ruleid]));
        $this->assertEquals(0, $DB->count_records('local_coursedynamicrules_action', ['ruleid' => $ruleid]));
    }
}
