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

use local_coursedynamicrules\local\restore_note;

/**
 * Removes the restore notes of a deleted course module.
 *
 * A note says that a restriction of the module lists a user without being attributable to the plugin
 * (CDR-PRIV-002-R2). With the module gone, so is the restriction, and the note would only name a user
 * against nothing.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_deleted {
    /**
     * Forget the notes of the deleted module.
     *
     * @param \core\event\course_module_deleted $event The event.
     * @return void
     */
    public static function observe(\core\event\course_module_deleted $event): void {
        restore_note::forget_module((int) $event->objectid);
    }
}
