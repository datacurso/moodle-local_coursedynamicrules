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
 * The lock that makes a rule unmodifiable after its first activation.
 *
 * Product requirement 2026-08-31: a rule may be edited only until it is activated for the FIRST
 * time - never again after that. Pausing and reactivating stay allowed forever; deleting the whole
 * rule stays allowed as the one escape hatch, behind its own RISK_DATALOSS capability.
 *
 * The fact is a single nullable column - timeactivated, stamped once, never cleared - and this
 * class is its one door: every write path that touches a rule's active state calls stamp_if_active()
 * after writing, every mutation path calls require_unlocked() before writing, and the save path of
 * a locked rule passes its payload through sanitise_locked_write() so a stale form cannot smuggle
 * an edit past the frozen UI. The decisions live together because splitting them is how this
 * plugin's last capability seam happened: one place decided, another place wrote.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_lock {
    /**
     * Record the first activation, if this rule is active and was never stamped.
     *
     * One atomic conditional UPDATE, and the helper reads the rule's state itself rather than
     * trusting a was/now pair from the caller: a caller passing post-save state cannot stamp
     * wrongly, and two concurrent activations cannot both pass a read-then-write check - whichever
     * UPDATE runs second matches zero rows. Idempotent by construction, so every write path that
     * touches 'active' may call it unconditionally after writing.
     *
     * @param int $ruleid The rule that may have just been activated.
     * @return void
     */
    public static function stamp_if_active(int $ruleid): void {
        global $DB;

        $DB->execute(
            "
            UPDATE {local_coursedynamicrules_rule}
               SET timeactivated = :now
             WHERE id = :id AND active = 1 AND timeactivated IS NULL",
            ['now' => time(), 'id' => $ruleid]
        );
    }

    /**
     * Whether this rule was ever activated - and is therefore no longer editable.
     *
     * @param int $ruleid
     * @return bool
     */
    public static function is_locked(int $ruleid): bool {
        global $DB;

        return $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $ruleid]) !== null;
    }

    /**
     * Whether the rule has what activation requires: at least one condition AND one action.
     *
     * Activation is the moment the rule locks forever, so activating an incomplete rule would
     * produce a locked rule that can never fire and can never be completed - its only exit is
     * deletion. The check lives with the lock because they are two halves of one contract, and the
     * form's validation and the confirm endpoint must agree on it.
     *
     * @param int $ruleid
     * @return bool
     */
    public static function is_complete(int $ruleid): bool {
        global $DB;

        return $DB->record_exists('local_coursedynamicrules_condition', ['ruleid' => $ruleid])
            && $DB->record_exists('local_coursedynamicrules_action', ['ruleid' => $ruleid]);
    }

    /**
     * Refuse to proceed when the rule is locked.
     *
     * For the mutation paths that a locked rule refuses outright: adding a component, deleting a
     * component, and any endpoint reached by URL - the controls are hidden too, but a URL is not
     * a menu.
     *
     * @param int $ruleid
     * @return void
     * @throws \moodle_exception When the rule was ever activated.
     */
    public static function require_unlocked(int $ruleid): void {
        if (self::is_locked($ruleid)) {
            throw new \moodle_exception('rulelocked', 'local_coursedynamicrules');
        }
    }

    /**
     * Reduce a locked rule's save payload to the one change it still accepts: the active toggle.
     *
     * Freezing form fields is cosmetics. A tab opened while the rule was still unlocked submits the
     * full payload after it locks, and update_record() would write it wholesale - the same
     * stale-state seam as every other decided-here-written-there bug. The server re-decides at
     * write time: id, active and timemodified pass through, every other field is replaced by what
     * the row already holds.
     *
     * Deliberately throws on an UNLOCKED rule: sanitising a legitimate edit would silently discard
     * it, and a caller that cannot tell which state it is in has a bug this exception surfaces.
     *
     * @param \stdClass $data The submitted rule payload (must carry id).
     * @return \stdClass The payload a locked rule accepts.
     * @throws \coding_exception When called for a rule that is not locked.
     */
    public static function sanitise_locked_write(\stdClass $data): \stdClass {
        global $DB;

        $stored = $DB->get_record('local_coursedynamicrules_rule', ['id' => (int) $data->id], '*', MUST_EXIST);

        if ($stored->timeactivated === null) {
            throw new \coding_exception(
                'sanitise_locked_write() called for an unlocked rule: it would silently discard a legitimate edit.'
            );
        }

        $clean = clone $stored;
        $clean->active = empty($data->active) ? 0 : 1;
        if (isset($data->timemodified)) {
            $clean->timemodified = $data->timemodified;
        }

        return $clean;
    }
}
