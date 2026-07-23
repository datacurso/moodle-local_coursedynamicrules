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
 * Tests for the no_course_access condition "never accessed" semantics.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\no_course_access\no_course_access_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_course_access_condition_test extends \advanced_testcase {
    /**
     * Build a no_course_access condition instance with the given period.
     *
     * @param string $periodvalue Period value.
     * @param string $periodunit Period unit.
     * @param int $courseid Course id.
     * @return no_course_access_condition
     */
    private function create_condition($periodvalue, $periodunit, $courseid): no_course_access_condition {
        $record = new stdClass();
        $record->ruleid = 1;
        $record->conditiontype = 'no_course_access';
        $record->params = json_encode([
            'periodvalue' => $periodvalue,
            'periodunit' => $periodunit,
            'nexttimeperiod' => 0,
        ]);
        return new no_course_access_condition($record, $courseid);
    }

    /**
     * Enrol a user with an explicit enrolment start time.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param int $timestart Enrolment start timestamp.
     * @return void
     */
    private function enrol_at(int $courseid, int $userid, int $timestart): void {
        global $DB;
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $manual->enrol_user($instance, $userid, $studentrole, $timestart);
    }

    /**
     * A recently enrolled user who never accessed must NOT match before the period elapses.
     *
     * @covers ::evaluate
     */
    public function test_recent_enrolment_never_accessed_does_not_match(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_at($course->id, $user->id, time() - (5 * DAYSECS)); // Enrolled 5 days ago.

        $condition = $this->create_condition('30', 'days', $course->id);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
    }

    /**
     * A long-enrolled user who never accessed must match once the period has elapsed.
     *
     * @covers ::evaluate
     */
    public function test_old_enrolment_never_accessed_matches(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_at($course->id, $user->id, time() - (40 * DAYSECS)); // Enrolled 40 days ago.

        $condition = $this->create_condition('30', 'days', $course->id);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertTrue($result);
    }

    /**
     * A user with no enrolment must not match (cannot measure the period).
     *
     * @covers ::evaluate
     */
    public function test_no_enrolment_does_not_match(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user(); // Not enrolled.

        $condition = $this->create_condition('30', 'days', $course->id);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
    }
}
