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

use grade_item;
use local_coursedynamicrules\local\service\grade_isolation_service;
use local_coursedynamicrules\local\service\grade_register_service;

/**
 * Shields everybody when a generated activity gains a grade column it did not have.
 *
 * Under "no grade" the plugin asks the module to be created ungraded, and when the module complies
 * there is no grade item at all - measured: zero rows in `grade_items`. So `apply()` has nothing to
 * act on and writes no exclusions, which is correct at that moment and wrong the instant somebody
 * changes their mind: a teacher opening the activity and setting a maximum grade creates the item,
 * and the column then counts against every student it was hidden from. Measured before this
 * existed: a bystander with 80% read 0% under Lowest grade and 40% under Mean, with nothing in any
 * log to explain it.
 *
 * `\core\event\grade_item_created` is the event that fires on that transition (measured - not
 * `grade_item_updated`, because there was no row to update).
 *
 * This observer writes `grade_grades` rows and NOTHING ELSE. It deliberately does not touch the
 * item: an earlier observer that re-parented the item from inside a grade-item event orphaned it,
 * because core holds a stale in-memory copy across `set_parent()` and writes it back afterwards,
 * and every gradebook page in the course then threw. Writing exclusions is safe by measurement too
 * - the write plus its `force_regrading()` fires no events at all, so this cannot re-enter.
 *
 * It runs on EVERY grade item created on the site, so the common case has to be cheap: one indexed
 * lookup on the course, and out.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_item_created {
    /**
     * Handle a grade item coming into existence.
     *
     * The whole body sits inside the guard. Core catches \Exception around observer callbacks
     * (lib/classes/event/manager.php:156); what this adds is the \Error half. Either way, editing
     * an activity's grade settings must never fail because of this plugin.
     *
     * @param \core\event\grade_item_created $event
     */
    public static function observe(\core\event\grade_item_created $event): void {
        global $DB;

        $courseid = (int) $event->courseid;
        $itemid = (int) $event->objectid;
        if (!$courseid || !$itemid) {
            return;
        }

        try {
            // The cheap gate. Every course this plugin never touched stops here.
            if (!$DB->record_exists(grade_register_service::TABLE, ['courseid' => $courseid])) {
                return;
            }

            $item = grade_item::fetch(['id' => $itemid, 'courseid' => $courseid]);
            if (!$item || $item->itemtype !== 'mod') {
                return;
            }

            $cm = get_coursemodule_from_instance(
                $item->itemmodule, (int) $item->iteminstance, $courseid, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }

            $row = $DB->get_record(grade_register_service::TABLE,
                ['courseid' => $courseid, 'cmid' => (int) $cm->id], 'id, userid, grademode', IGNORE_MULTIPLE);
            if (!$row) {
                // Somebody else's activity. Most grade items created on a site land here.
                return;
            }

            if (!grade_isolation_service::is_at_root($item)) {
                debugging(get_string('error_grade_item_not_at_root',
                    'local_coursedynamicrules', $item->itemname), DEBUG_DEVELOPER);

                return;
            }

            // Only own credit spares its recipient, and a severed row (userid 0) spares nobody.
            $keep = grade_isolation_service::clean_mode($row->grademode)
                    === grade_isolation_service::MODE_OWN
                ? ((int) $row->userid ?: null)
                : null;

            grade_isolation_service::exclude_all_but(
                $item, grade_isolation_service::gradebook_users($courseid), $keep);
        } catch (\Throwable $e) {
            debugging(
                get_string('error_grade_column_appeared', 'local_coursedynamicrules', $e->getMessage()),
                DEBUG_DEVELOPER
            );
        }
    }
}
