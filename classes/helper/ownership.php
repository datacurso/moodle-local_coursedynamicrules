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

namespace local_coursedynamicrules\helper;

/**
 * Helper to enforce that rules, conditions and actions belong to the requested course.
 *
 * Pages check capabilities against the course id supplied in the request, so objects must also be
 * confirmed to belong to that course to prevent cross-course access (loading a foreign course's
 * rule/condition/action by id).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ownership {
    /**
     * Fetch a rule ensuring it belongs to the given course.
     *
     * @param int $ruleid Rule id.
     * @param int $courseid Course id from the request.
     * @return \stdClass The rule record.
     * @throws \dml_missing_record_exception If the rule does not belong to the course.
     */
    public static function get_rule($ruleid, $courseid) {
        global $DB;
        return $DB->get_record('local_coursedynamicrules_rule',
            ['id' => $ruleid, 'courseid' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Whether the rule belongs to the given course.
     *
     * @param int $ruleid Rule id.
     * @param int $courseid Course id from the request.
     * @return bool
     */
    public static function rule_belongs_to_course($ruleid, $courseid) {
        global $DB;
        return $DB->record_exists('local_coursedynamicrules_rule',
            ['id' => $ruleid, 'courseid' => $courseid]);
    }

    /**
     * Fetch a condition ensuring its rule belongs to the given course.
     *
     * @param int $conditionid Condition id.
     * @param int $courseid Course id from the request.
     * @return \stdClass The condition record.
     * @throws \dml_missing_record_exception If the condition's rule does not belong to the course.
     */
    public static function get_condition($conditionid, $courseid) {
        global $DB;
        return $DB->get_record_sql(
            "SELECT c.*
               FROM {local_coursedynamicrules_condition} c
               JOIN {local_coursedynamicrules_rule} r ON r.id = c.ruleid
              WHERE c.id = :id AND r.courseid = :courseid",
            ['id' => $conditionid, 'courseid' => $courseid], MUST_EXIST);
    }

    /**
     * Fetch an action ensuring its rule belongs to the given course.
     *
     * @param int $actionid Action id.
     * @param int $courseid Course id from the request.
     * @return \stdClass The action record.
     * @throws \dml_missing_record_exception If the action's rule does not belong to the course.
     */
    public static function get_action($actionid, $courseid) {
        global $DB;
        return $DB->get_record_sql(
            "SELECT a.*
               FROM {local_coursedynamicrules_action} a
               JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
              WHERE a.id = :id AND r.courseid = :courseid",
            ['id' => $actionid, 'courseid' => $courseid], MUST_EXIST);
    }
}
