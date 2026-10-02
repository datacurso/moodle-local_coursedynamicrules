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
 * Testable dynamic_grade_in_activity_form subclass exposing the protected MoodleQuickForm.
 *
 * dynamic_form extends moodleform, so the backing MoodleQuickForm lives in the protected $_form
 * property; a subclass hands it to the test through a public accessor, letting the tests assert
 * element existence/values without reflection.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_dynamic_grade_in_activity_form extends dynamic_grade_in_activity_form {
    /**
     * Return the protected MoodleQuickForm this form wraps.
     *
     * @return \MoodleQuickForm
     */
    public function get_mform_for_test(): \MoodleQuickForm {
        return $this->_form;
    }

    /**
     * Run the form's own access check, which core calls before it renders or accepts anything.
     *
     * @return void
     */
    public function check_access_for_test(): void {
        $this->check_access_for_dynamic_submission();
    }
}
