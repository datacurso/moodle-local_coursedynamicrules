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

        // The whole row, not just the id: each rule that goes down with the course is reported as
        // its own deletion, and that report is only readable afterwards if it carries what was
        // lost. A log entry naming an id whose row no longer exists answers nothing.
        $rules = $DB->get_records('local_coursedynamicrules_rule', ['courseid' => $courseid]);
        if ($rules) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($rules));
            $DB->delete_records_select('local_coursedynamicrules_condition', "ruleid $insql", $params);
            $DB->delete_records_select('local_coursedynamicrules_action', "ruleid $insql", $params);
        }
        $DB->delete_records('local_coursedynamicrules_rule', ['courseid' => $courseid]);

        // Reported AFTER the rows are gone, so nothing is announced that a later failure could
        // leave standing. The context comes from the event and never from
        // context_course::instance(): core captures the course context, deletes the context row and
        // only then triggers this event (lib/moodlelib.php, delete_course()), so there is no course
        // context left to instantiate by the time this runs.
        $context = $event->get_context();
        foreach ($rules as $rule) {
            $deleted = \local_coursedynamicrules\event\rule_deleted::create([
                'context' => $context,
                'objectid' => $rule->id,
            ]);
            $deleted->add_record_snapshot('local_coursedynamicrules_rule', $rule);
            $deleted->trigger();
        }
    }
}
