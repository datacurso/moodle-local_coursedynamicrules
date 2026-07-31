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

namespace local_coursedynamicrules\form\conditions;

use local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition;

/**
 * Tests that editing a course_inactivity condition runs through the same validation() as creation.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\form\conditions\course_inactivity_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_inactivity_form_test extends \advanced_testcase {
    /**
     * A form constructed exactly as conditions.php does for an edit (customdata carries a stored
     * 'record') must still reject an invalid recurring interval via the identical validation()
     * used at creation — editing does not bypass or relax server-side validation.
     *
     * @covers ::validation
     */
    public function test_validation_rejects_invalid_recurring_interval_on_edit_form(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['startdate' => strtotime('2025-01-01')]);

        $form = new course_inactivity_form(
            new \moodle_url('/local/coursedynamicrules/conditions.php'),
            [
                'courseid' => $course->id,
                'ruleid' => 1,
                'record' => (object) [
                    'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
                    'timeintervals' => '7',
                    'intervalunit' => 'days',
                    'basedatetype' => course_inactivity_condition::DATE_FROM_ENROLLMENT,
                ],
            ]
        );

        $errors = $form->validation([
            'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
            'recurringinterval' => 'abc',
            'intervalunit' => 'days',
            'basedatetype' => course_inactivity_condition::DATE_FROM_ENROLLMENT,
        ], []);

        $this->assertArrayHasKey('recurringinterval', $errors);
    }

    /**
     * The same edit-mode form must accept valid input (no false-positive rejection introduced by
     * the preload path).
     *
     * @covers ::validation
     */
    public function test_validation_accepts_valid_recurring_interval_on_edit_form(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['startdate' => strtotime('2025-01-01')]);

        $form = new course_inactivity_form(
            new \moodle_url('/local/coursedynamicrules/conditions.php'),
            [
                'courseid' => $course->id,
                'ruleid' => 1,
                'record' => (object) [
                    'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
                    'timeintervals' => '7',
                    'intervalunit' => 'days',
                    'basedatetype' => course_inactivity_condition::DATE_FROM_ENROLLMENT,
                ],
            ]
        );

        $errors = $form->validation([
            'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
            'recurringinterval' => '14',
            'intervalunit' => 'days',
            'basedatetype' => course_inactivity_condition::DATE_FROM_ENROLLMENT,
        ], []);

        $this->assertArrayNotHasKey('recurringinterval', $errors);
    }
}
