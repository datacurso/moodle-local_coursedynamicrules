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

use local_coursedynamicrules\local\service\grade_combination_service;

/**
 * Carries the grade of a generated reinforcement activity back onto the activity it recovers.
 *
 * This fires on EVERY grade written anywhere on the site, so the common case - a grade that has
 * nothing to do with this plugin - has to cost as little as possible: one indexed lookup on the
 * student, and out.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_activity_graded {
    /**
     * Handle a grade being written.
     *
     * @param \core\event\user_graded $event
     */
    public static function observe(\core\event\user_graded $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        if (!$userid) {
            return;
        }

        $grade = $event->get_grade();
        if ($grade->grade_item->itemtype !== 'mod') {
            // Course and category totals are recalculated constantly; only a module grade can be
            // a reinforcement result.
            return;
        }

        // The cheap gate. Nearly every grade on the site stops here, on one indexed query.
        if (!$DB->record_exists(grade_combination_service::TABLE, ['userid' => $userid])) {
            return;
        }

        $cm = get_coursemodule_from_instance(
            $grade->grade_item->itemmodule,
            (int) $grade->grade_item->iteminstance,
            (int) $grade->grade_item->courseid,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return;
        }

        try {
            grade_combination_service::handle_graded((int) $cm->id, $userid);
        } catch (\Throwable $e) {
            // An observer that throws aborts the grading transaction that triggered it, which
            // would leave the teacher unable to grade at all. Report and let grading finish.
            debugging(
                get_string('error_grade_combination_failed', 'local_coursedynamicrules', $e->getMessage()),
                DEBUG_DEVELOPER
            );
        }
    }
}
