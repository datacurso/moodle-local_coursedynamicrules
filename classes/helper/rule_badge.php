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
 * The one place that decides which of the four states a rule's badge shows.
 *
 * Product directives 2026-08-31/09-01, refined 2026-09-03: a rule reads as exactly one of
 *
 * - ACTIVE   - running now, whether or not it has ever fired. Event-driven rules stay active and
 *              fire repeatedly, so "executed" on a live rule would be noise.
 * - EXECUTED - the ENGINE switched it off after running it. This is precisely a one-shot cron rule
 *              (no_complete_activity) that self-deactivated, recorded by the timeautodeactivated
 *              stamp. It is NEVER a rule a human paused: the earlier code inferred "executed" from
 *              lastexecutiontime, but an event-driven rule stamps that on every run and is never
 *              self-deactivated, so pausing it by hand wrongly showed "executed".
 * - PAUSED   - activated at least once (so sealed), now stopped, and NOT by the engine: a human
 *              cleared the active toggle. Any manual toggle clears the self-deactivation stamp, so a
 *              stopped-and-unstamped-but-sealed rule is exactly a manual pause.
 * - INACTIVE - never activated (the only editable state).
 *
 * Pure and row-only so the listing keeps its no-query-per-rule property and so the decision is
 * testable through the public API without a page render.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_badge {
    /** @var string Running now. */
    const STATE_ACTIVE = 'active';

    /** @var string The engine switched it off after running it (one-shot cron rule). */
    const STATE_EXECUTED = 'executed';

    /** @var string Sealed, stopped by a human, never self-deactivated. */
    const STATE_PAUSED = 'paused';

    /** @var string Never activated. */
    const STATE_INACTIVE = 'inactive';

    /**
     * Decide the badge state for an already-fetched rule row.
     *
     * The order is the whole logic: active wins outright; then the engine's self-deactivation stamp
     * earns "executed"; then any remaining sealed-but-stopped rule is a manual pause; everything
     * else was never activated.
     *
     * @param \stdClass $rule A rule row carrying active, timeactivated and timeautodeactivated.
     * @return string One of the STATE_* constants.
     */
    public static function state(\stdClass $rule): string {
        if (!empty($rule->active)) {
            return self::STATE_ACTIVE;
        }

        if (!empty($rule->timeautodeactivated)) {
            return self::STATE_EXECUTED;
        }

        if (rule_lock::is_locked_row($rule)) {
            return self::STATE_PAUSED;
        }

        return self::STATE_INACTIVE;
    }
}
