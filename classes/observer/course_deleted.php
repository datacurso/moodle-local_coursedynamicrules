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
 * Removes a course's rules (and their conditions and actions) when the course is deleted.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_deleted {
    /**
     * Delete all plugin data belonging to the deleted course.
     *
     * @param \core\event\course_deleted $event The course deleted event.
     */
    public static function observe(\core\event\course_deleted $event) {
        global $DB;

        $courseid = $event->objectid;

        $ruleids = $DB->get_fieldset_select('local_coursedynamicrules_rule', 'id', 'courseid = ?', [$courseid]);
        if ($ruleids) {
            [$insql, $params] = $DB->get_in_or_equal($ruleids);
            $DB->delete_records_select('local_coursedynamicrules_condition', "ruleid $insql", $params);
            $DB->delete_records_select('local_coursedynamicrules_action', "ruleid $insql", $params);
        }
        $DB->delete_records('local_coursedynamicrules_rule', ['courseid' => $courseid]);

        // Rows here name a student. Leaving them behind after the course is gone is both dead data
        // and a privacy liability, and it keeps the enrolment sweep's course lookup answering yes
        // for a course that no longer exists.
        $DB->delete_records(
            \local_coursedynamicrules\local\service\grade_register_service::TABLE,
            ['courseid' => $courseid]
        );
    }
}
