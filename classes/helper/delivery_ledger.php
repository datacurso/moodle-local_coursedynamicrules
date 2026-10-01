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
 * The record of inactivity milestones already delivered to each student.
 *
 * An inactivity condition is met during a six-hour window after each milestone, the same length as
 * the task's cadence, so in normal operation one run falls inside each window. Nothing guaranteed
 * it: a run launched by hand, or cron catching up after a delay, landed a second run inside the same
 * window and notified the same student again for the same milestone (MDL-UNIT-010). This ledger is
 * what tells "already delivered" apart: at most one delivery per condition, student and milestone.
 *
 * The milestone is keyed together with its ANCHOR (enrolment, course start or activation time).
 * Moving the course start date back can land a later milestone on the very instant an earlier one
 * fell on; keyed by the instant alone, that legitimate delivery would be blocked.
 *
 * The one owner of the table: the condition reads and writes through it, and every deletion path
 * (rule, condition, course, user, privacy) clears through it or through its TABLE constant.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delivery_ledger {
    /** @var string DB table holding one row per delivered milestone. */
    const TABLE = 'local_coursedynamicrules_delivery';

    /**
     * The key of one milestone measured from one anchor.
     *
     * @param int $anchor The base date the milestones are measured from.
     * @param int $milestone The moment the milestone fell due.
     * @return string
     */
    public static function milestone_key(int $anchor, int $milestone): string {
        return $anchor . ':' . $milestone;
    }

    /**
     * The moment a milestone key fell due, for display.
     *
     * @param string $key A key built by milestone_key().
     * @return int|null The milestone timestamp, or null when the key is not in that shape.
     */
    public static function milestone_of(string $key): ?int {
        $parts = explode(':', $key);
        return count($parts) === 2 && ctype_digit($parts[1]) ? (int) $parts[1] : null;
    }

    /**
     * Whether this milestone was already delivered to the student by this condition.
     *
     * @param int $conditionid The condition.
     * @param int $userid The student.
     * @param string $key The milestone key.
     * @return bool
     */
    public static function is_delivered(int $conditionid, int $userid, string $key): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, [
            'conditionid' => $conditionid,
            'userid' => $userid,
            'milestonekey' => $key,
        ]);
    }

    /**
     * Record a delivery. Recording one that already exists keeps the single row.
     *
     * Two runs racing on the same milestone both pass the existence check; the unique index makes the
     * second insert fail, and that failure means exactly "already delivered", so it is dropped. The
     * notification of the losing run has already gone out by then - the ledger narrows the window to
     * the race itself, it cannot close it without a lock around the actions.
     *
     * @param int $ruleid The rule the condition belongs to.
     * @param int $conditionid The condition.
     * @param int $userid The student.
     * @param string $key The milestone key.
     * @return void
     */
    public static function record(int $ruleid, int $conditionid, int $userid, string $key): void {
        global $DB;

        if (self::is_delivered($conditionid, $userid, $key)) {
            return;
        }

        try {
            $DB->insert_record(self::TABLE, (object) [
                'ruleid' => $ruleid,
                'conditionid' => $conditionid,
                'userid' => $userid,
                'milestonekey' => $key,
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            if (!self::is_delivered($conditionid, $userid, $key)) {
                throw $e;
            }
        }
    }

    /**
     * Forget every delivery of a rule.
     *
     * @param int $ruleid The rule.
     * @return void
     */
    public static function delete_for_rule(int $ruleid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['ruleid' => $ruleid]);
    }

    /**
     * Forget every delivery of the given rules.
     *
     * @param int[] $ruleids The rules.
     * @return void
     */
    public static function delete_for_rules(array $ruleids): void {
        global $DB;
        if ($ruleids === []) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($ruleids));
        $DB->delete_records_select(self::TABLE, "ruleid $insql", $params);
    }

    /**
     * Forget every delivery of a condition.
     *
     * @param int $conditionid The condition.
     * @return void
     */
    public static function delete_for_condition(int $conditionid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['conditionid' => $conditionid]);
    }

    /**
     * Forget every delivery made to a user.
     *
     * @param int $userid The user.
     * @return void
     */
    public static function delete_for_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }
}
