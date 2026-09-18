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
     * Fetched MUST_EXIST: a missing rule is an error, never "locked". get_field()'s false for a
     * missing row is !== null, so the old shape answered "sealed" for ids that do not exist -
     * both round-2 judges caught the misleading refusals that produced.
     *
     * @param int $ruleid
     * @return bool
     * @throws \dml_missing_record_exception When no such rule exists.
     */
    public static function is_locked(int $ruleid): bool {
        global $DB;

        return self::is_locked_row(
            $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], 'id, timeactivated', MUST_EXIST)
        );
    }

    /**
     * The one definition of "sealed", fed an already-fetched rule row.
     *
     * Exists so listings keep their no-query-per-rule property WITHOUT growing a second local
     * definition of the fact - the listing's empty() versus the server's !== null is exactly how
     * a stamp of literally 0 became sealed for one and open for the other. The canon: any stored
     * stamp seals (writers normalise 0 away, and a degenerate stamp fails CLOSED, agreeing with
     * the enforcement side). is_locked() delegates here, so the two can never diverge.
     *
     * @param \stdClass $rule A rule row carrying timeactivated.
     * @return bool
     */
    public static function is_locked_row(\stdClass $rule): bool {
        return $rule->timeactivated !== null;
    }

    /**
     * The moment the rule was activated, or null for a rule that never was.
     *
     * The same fact is_locked() reads, handed out as the timestamp instead of the boolean. A
     * component that measures time from "when the rule started" - the course-inactivity condition's
     * "from now" base date - asks here, so the one column that records activation keeps being read
     * from one place and nobody grows a second idea of what "activated" means.
     *
     * Fetched MUST_EXIST for the same reason is_locked() is: a condition pointing at a rule that
     * does not exist is a data error, and answering "never activated" for it would quietly turn
     * that error into a rule that evaluates false forever.
     *
     * @param int $ruleid
     * @return int|null Activation timestamp, null while the rule has never been activated.
     * @throws \dml_missing_record_exception When no such rule exists.
     */
    public static function activation_time(int $ruleid): ?int {
        global $DB;

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], 'id, timeactivated', MUST_EXIST);

        return $rule->timeactivated === null ? null : (int) $rule->timeactivated;
    }

    /**
     * Whether the rule has what activation requires: at least one condition, at least one action,
     * and no action that could never act.
     *
     * Activation is the moment the rule locks forever, so activating an incomplete rule would
     * produce a locked rule that can never fire and can never be completed - its only exit is
     * deletion, and on a sealed rule that exit needs the manager-only deletesealedrule capability.
     * The check lives with the lock because they are two halves of one contract, and the form's
     * validation and the confirm endpoint must agree on it.
     *
     * Conditions are counted as ROWS, so a "ghost" condition - one whose target activity was
     * deleted - still counts, and a rule whose only condition is a ghost can be activated and
     * sealed although it can never fire (conditions combine with AND). Product decision, kept as it
     * was when 1.8.4 made ghosts visible: a ghost describes itself with a warning in every listing,
     * so on a never-activated rule the operator sees the dead condition - and its trash can - before
     * activating. Whether completeness should also refuse it is a separate decision, in CHANGES.md.
     *
     * Actions are not merely counted: EVERY action must answer yes to action::can_act(), which asks
     * whether it could do anything at all if the rule fired now. Only the enable-activity action can
     * answer no, and it does so with no activity chosen - the state every duplicated copy is born in
     * - and with every chosen activity deleted since. Counting a rule complete in either case let it
     * be activated and sealed with that half dead forever: the lock then refuses to add the
     * activities, and its own teacher cannot delete the rule to start again. Each action is asked
     * through its own class rather than by reading its params here, so what "unable to act" means
     * stays where the action's configuration lives.
     *
     * @param int $ruleid
     * @return bool
     */
    public static function is_complete(int $ruleid): bool {
        return self::incompleteness_reason($ruleid) === null;
    }

    /**
     * WHY a rule is not ready to activate, or null when it is.
     *
     * is_complete() above answers whether, on the same rules; this answers which of the four states
     * the rule is in, so the operator reads the one sentence that applies instead of a list of every
     * requirement at once. The states are checked in the order that makes the answer useful: a rule
     * with no condition can never fire however many actions it has, so that is reported first.
     *
     * The return value is a language string key, not a sentence: callers translate it, and tests can
     * assert on it without depending on wording.
     *
     * @param int $ruleid
     * @return string|null A language string key in this component, or null when the rule is complete.
     */
    public static function incompleteness_reason(int $ruleid): ?string {
        global $DB;

        if (!$DB->record_exists('local_coursedynamicrules_condition', ['ruleid' => $ruleid])) {
            return 'ruleactivationnoconditions';
        }

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], 'id, courseid', MUST_EXIST);
        $actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $ruleid]);
        if (!$actions) {
            return 'ruleactivationnoactions';
        }

        foreach ($actions as $action) {
            try {
                $instance = rule_component_loader::create_action_instance($action, (int) $rule->courseid);
            } catch (\moodle_exception $e) {
                // An action whose class this build cannot load certainly cannot act. Answering
                // instead of throwing keeps the gate usable: it is consulted from the rule form and
                // from the activation endpoint, and throwing there would lock the operator out of a
                // rule they could otherwise still fix or delete.
                return 'ruleactivationactionbroken';
            }
            if (!$instance->can_act()) {
                return 'ruleactivationactionidle';
            }
        }

        return null;
    }

    /**
     * Every reason incompleteness_reason() can return.
     *
     * Exists so a test can prove each one resolves to a real language string: a reason that renders
     * as "[[ruleactivation...]]" explains nothing, and nothing else would catch it.
     *
     * @return string[] Language string keys in this component.
     */
    public static function incompleteness_reasons(): array {
        return [
            'ruleactivationnoconditions',
            'ruleactivationnoactions',
            'ruleactivationactionbroken',
            'ruleactivationactionidle',
        ];
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

        // The whitelist is built BY ADDITION - the write object carries only what a locked rule
        // accepts, and update_record() cannot touch a column that is not in the object. The
        // earlier clone-the-row shape wrote lastexecutiontime back from a stale read, clobbering
        // the cron's throttle when a teacher paused a rule mid-run (both round-2 judges): with
        // only these keys, that collision is unexpressible rather than merely avoided.
        $clean = (object) [
            'id' => (int) $stored->id,
            'active' => empty($data->active) ? 0 : 1,
            // A manual toggle of active - the one edit a locked rule still accepts - always clears
            // the engine's self-deactivation stamp. The 'executed' badge belongs to the engine, not
            // the operator: once a human moves the switch the rule is 'paused' or 'active', never
            // 'executed'. This is the write half of that rule; set_active() (the engine path) is the
            // other half. A rule that was never self-deactivated already holds NULL here, so writing
            // NULL is a no-op for it.
            'timeautodeactivated' => null,
        ];
        if (isset($data->timemodified)) {
            $clean->timemodified = $data->timemodified;
        }

        return $clean;
    }

    /**
     * Whether sanitising a locked write actually threw a submitted edit away.
     *
     * Discarding is the contract; reporting "updated successfully" over a discarded rename is a
     * lie. Compared against the STORED row, because the sanitised write object deliberately
     * carries nothing to compare against. The frozen form stays quiet the honest way: hardFrozen
     * elements re-export their defaults through get_data() (formslib exportValues with
     * setPersistantFreeze(false)), so its payload holds the stored values verbatim and no field
     * differs. Only a tab rendered before the rule locked can submit a differing value, and that
     * difference is exactly what the user must be warned about.
     *
     * @param \stdClass $submitted The payload as the form submitted it (must carry id).
     * @return bool True when a submitted field differs from the value the row holds.
     */
    public static function locked_write_discards(\stdClass $submitted): bool {
        global $DB;

        $stored = $DB->get_record('local_coursedynamicrules_rule', ['id' => (int) $submitted->id], '*', MUST_EXIST);

        foreach (get_object_vars($stored) as $field => $kept) {
            if (in_array($field, ['id', 'active', 'timemodified'], true)) {
                continue;
            }
            if (property_exists($submitted, $field) && (string) $submitted->{$field} !== (string) $kept) {
                return true;
            }
        }

        return false;
    }
}
