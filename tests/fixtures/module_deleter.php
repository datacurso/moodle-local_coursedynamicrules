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

namespace local_coursedynamicrules\tests;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Deletes a course module through the API of the running Moodle branch.
 *
 * Moodle 5.2 deprecates course_delete_module() in favour of cmactions::delete(), and the
 * deprecation notice is a debugging() call that breaks the tests asserting on debugging output.
 * Moodle 4.5 and 5.0 have no cmactions::delete(), so the tests go through this helper to run
 * unchanged on every branch the plugin supports.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_deleter {
    /**
     * Delete a course module, synchronously or through the recycle-bin ad hoc task.
     *
     * @param int $cmid The course module id.
     * @param bool $async True to schedule the deletion, as the course page does.
     */
    public static function delete(int $cmid, bool $async = false): void {
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            $courseid = \context_module::instance($cmid)->get_course_context()->instanceid;
            \core_courseformat\formatactions::cm($courseid)->delete($cmid, $async);
            return;
        }
        course_delete_module($cmid, $async);
    }
}
