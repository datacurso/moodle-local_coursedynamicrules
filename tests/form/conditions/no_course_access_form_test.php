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

/**
 * Tests for no_course_access_form server-side validation.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\form\conditions\no_course_access_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_course_access_form_test extends \advanced_testcase {
    /**
     * FIX4: the periodunit <select> only offers hours/days/weeks, but server-side validation must
     * not trust that a submission actually went through it - a tampered POST with an unrecognised
     * unit would otherwise reach strtotime() in the condition and silently misbehave.
     *
     * @covers ::validation
     */
    public function test_validation_rejects_unrecognised_periodunit(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $form = new no_course_access_form(
            new \moodle_url('/local/coursedynamicrules/conditions.php'),
            [
                'courseid' => $course->id,
                'ruleid' => 1,
            ]
        );

        $errors = $form->validation([
            'periodvalue' => '10',
            'periodunit' => 'fortnights',
        ], []);

        $this->assertArrayHasKey('period_group', $errors);
    }

    /**
     * A recognised periodunit (one of hours/days/weeks) must not be rejected.
     *
     * @covers ::validation
     */
    public function test_validation_accepts_recognised_periodunit(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $form = new no_course_access_form(
            new \moodle_url('/local/coursedynamicrules/conditions.php'),
            [
                'courseid' => $course->id,
                'ruleid' => 1,
            ]
        );

        $errors = $form->validation([
            'periodvalue' => '10',
            'periodunit' => 'days',
        ], []);

        $this->assertArrayNotHasKey('period_group', $errors);
    }
}
