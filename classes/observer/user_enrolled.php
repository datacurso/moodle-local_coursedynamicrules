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

use local_coursedynamicrules\local\service\grade_register_service;
use local_coursedynamicrules\local\service\grade_isolation_service;

/**
 * Shields a student whose enrolment starts, or becomes active, after a reinforcement was generated.
 *
 * Isolation is written per student at generation time, which covers everybody the gradebook counted
 * then. Somebody who arrives afterwards - a new enrolment, or a suspended one reactivated - has no
 * row, so every reinforcement column in the course is empty for them, and under Mean, Median or
 * Lowest grade an empty column counts as a zero. Nothing else would ever notice: the activity is
 * not new, nobody is graded, no rule runs.
 *
 * This fires on EVERY enrolment change on the site, so the common case - a course this plugin has
 * never touched - has to cost as little as possible: one indexed lookup, and out.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_enrolled {
    /**
     * Handle a user being enrolled in a course.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function observe(\core\event\user_enrolment_created $event): void {
        self::sweep((int) $event->courseid, (int) $event->relateduserid);
    }

    /**
     * Handle an existing enrolment changing - status, start date or end date.
     *
     * Registered separately because the two events carry the same payload but mean different
     * things: this one is the path a suspended or expired student takes back into the gradebook,
     * and shielding only on creation left them counted and unprotected.
     *
     * @param \core\event\user_enrolment_updated $event
     */
    public static function observe_updated(\core\event\user_enrolment_updated $event): void {
        self::sweep((int) $event->courseid, (int) $event->relateduserid);
    }

    /**
     * Handle a role being assigned in a course.
     *
     * Enrolling and being given a role are two events, and the gradebook counts a student only
     * once they hold a $CFG->gradebookroles role. At user_enrolment_created that role does not
     * exist yet, so a sweep gated on it skipped the very student it was written for - and a sync
     * plugin that enrols first and assigns later has the same shape.
     *
     * @param \core\event\role_assigned $event
     */
    public static function observe_role(\core\event\role_assigned $event): void {
        try {
            $context = \context::instance_by_id((int) $event->contextid, IGNORE_MISSING);
        } catch (\Throwable $e) {
            return;
        }
        if (!$context instanceof \context_course) {
            return;
        }

        self::sweep((int) $context->instanceid, (int) $event->relateduserid);
    }

    /**
     * Shield one student from every reinforcement column already in a course.
     *
     * The whole body sits inside the guard. Core wraps observer callbacks in a catch for
     * \Exception (lib/classes/event/manager.php:154), so what this adds is the \Error half -
     * a TypeError on a malformed register row would otherwise escape. Either way, an enrolment
     * must never fail because of this plugin.
     *
     * @param int $courseid
     * @param int $userid
     */
    private static function sweep(int $courseid, int $userid): void {
        global $DB;

        if (!$userid || !$courseid) {
            return;
        }

        try {
            // The cheap gate. Almost every enrolment on the site stops here, on one indexed query.
            if (!$DB->record_exists(grade_register_service::TABLE, ['courseid' => $courseid])) {
                return;
            }

            // Only shield somebody the gradebook actually aggregates for. Writing rows for a
            // teacher or a manager would put non-students in every reinforcement column, and would
            // also disagree with apply(), which asks the same question.
            //
            // One row, not the cohort: this runs on every enrolment and every role assignment on
            // the site, and fetching a 1500-student list to answer a one-student question would
            // undo the point of the cheap gate above.
            if (!grade_isolation_service::is_gradebook_user($courseid, $userid)) {
                return;
            }

            foreach (grade_register_service::generated_in_course($courseid) as $row) {
                $cm = get_coursemodule_from_id(null, (int) $row->cmid, 0, false, IGNORE_MISSING);
                if (!$cm) {
                    // The generated activity has been deleted; its grade item went with it.
                    continue;
                }

                // The gradebook decides whether there is a column, not the stored mode. A row
                // saved as 'nograde' still has one whenever the module ignored the request to be
                // created ungraded - trusting the mode here is what left those courses exposed.
                $items = grade_isolation_service::gradable_items(
                    $courseid, $cm->modname, (int) $cm->instance);
                if (empty($items)) {
                    continue;
                }

                // Under "no grade" the column counts for nobody, so the arriving student is
                // shielded like everyone else. Under "counts for its recipient" they are shielded
                // too - they are not that recipient, or they would not be arriving now.
                $keep = grade_isolation_service::clean_mode($row->grademode)
                        === grade_isolation_service::MODE_OWN
                    ? (int) $row->userid
                    : null;

                foreach ($items as $item) {
                    if (!grade_isolation_service::is_at_root($item)) {
                        // Somebody filed this column into a grade category after it was generated.
                        // An exclusion row there protects nobody - the category comes out empty for
                        // the excluded student and an empty category counts as a zero one level up -
                        // so writing one and reporting success would be the same silent lie the
                        // rest of this service exists to avoid.
                        debugging(get_string('error_grade_item_not_at_root',
                            'local_coursedynamicrules', $item->itemname), DEBUG_DEVELOPER);
                        continue;
                    }
                    grade_isolation_service::exclude_all_but($item, [$userid], $keep);
                }
            }
        } catch (\Throwable $e) {
            debugging(
                get_string('error_grade_enrolment_sweep_failed', 'local_coursedynamicrules', $e->getMessage()),
                DEBUG_DEVELOPER
            );
        }
    }
}
