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

use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\local\owned_gate_eraser;

/**
 * Scrubs a deleted user's id from the access restrictions this plugin wrote.
 *
 * The enable-activity action grants a student access by writing their id into a managed module's
 * user restriction, and the AI action restricts the activity it generates to its student the same
 * way. Core's availability_user keeps no privacy data and does not react to a user deletion, so
 * that id would otherwise stay behind as an orphan reference for good (MDL-E2E-011).
 *
 * Two passes. Each enable-activity action knows which node of its managed modules is its own -
 * the marker, or the sole unmarked user node - and is handed the deleted user first. Then the
 * privacy provider's own eraser (owned_gate_eraser) runs, which reaches what the first pass cannot:
 * marked nodes of ANY action type, and the AI activities found through their key even when the
 * marker was lost. The AI action records its activity in no params, so before the second pass an
 * ordinary account deletion left that id behind (CDR-PRIV-002).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_deleted {
    /**
     * Remove the deleted user from every restriction node of this plugin.
     *
     * @param \core\event\user_deleted $event The user deleted event.
     * @return void
     */
    public static function observe(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;

        // Every enable-activity action on the site, with the course its rule belongs to: the action
        // resolves its modules and its own restriction node against that course.
        $sql = "SELECT a.*, r.courseid
                  FROM {local_coursedynamicrules_action} a
                  JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                 WHERE a.actiontype = :actiontype";
        $courseids = [];
        $actions = $DB->get_recordset_sql($sql, ['actiontype' => 'enableactivity']);
        foreach ($actions as $record) {
            $courseid = (int) $record->courseid;
            $action = new enableactivity_action($record, $courseid);
            if ($action->revoke_user($userid)) {
                $courseids[$courseid] = $courseid;
            }
        }
        $actions->close();

        // After the loop above, so an enable-activity node it already handled is simply found empty.
        // An ambiguous AI activity is left alone and reported as revoke_user() reports its own: a
        // user deletion must not fail half-way, and the event manager would swallow an exception.
        $erased = owned_gate_eraser::erase_user($userid);
        foreach ($erased['courseids'] as $courseid) {
            $courseids[$courseid] = $courseid;
        }
        foreach ($erased['ambiguous'] as $cmid) {
            debugging(
                'local_coursedynamicrules: course module ' . $cmid . ' has more than one unmarked user restriction '
                    . 'listing only the deleted user ' . $userid . ', which its AI activity key names; none was '
                    . 'edited - manual cleanup required',
                DEBUG_DEVELOPER
            );
        }

        // One cache rebuild per course that changed, however many actions it holds.
        foreach ($courseids as $courseid) {
            rebuild_course_cache($courseid, true);
        }
    }
}
