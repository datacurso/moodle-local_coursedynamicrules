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

namespace local_coursedynamicrules\form\actions;

/**
 * Testable createaiactivity_form subclass exposing the protected MoodleQuickForm by inheritance.
 *
 * Same pattern as the other testable form fixtures: moodleform holds its MoodleQuickForm in the
 * protected $_form property, so a subclass can hand it to a test without reflection.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_createaiactivity_form extends createaiactivity_form {
    /**
     * Return the protected MoodleQuickForm this form wraps.
     *
     * @return \MoodleQuickForm
     */
    public function get_mform_for_test(): \MoodleQuickForm {
        return $this->_form;
    }
}
