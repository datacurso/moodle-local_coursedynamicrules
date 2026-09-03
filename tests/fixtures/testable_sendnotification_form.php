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
 * Testable sendnotification_form subclass exposing the protected MoodleQuickForm by inheritance.
 *
 * The end-to-end preload tests need the real definition()/set_data() run to assert the actual
 * exported element values; a subclass hands the protected $_form to the test through a public
 * accessor, so those assertions never need reflection.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_sendnotification_form extends sendnotification_form {
    /**
     * Return the protected MoodleQuickForm this form wraps.
     *
     * @return \MoodleQuickForm
     */
    public function get_mform_for_test(): \MoodleQuickForm {
        return $this->_form;
    }
}
