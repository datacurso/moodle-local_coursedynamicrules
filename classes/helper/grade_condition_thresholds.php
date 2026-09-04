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
 * Resolves a grade_in_activity condition's stored thresholds to a map keyed by the STABLE itemnumber.
 *
 * The condition stores, per grade item, an optional "grade >= X" and "grade < Y" threshold. The
 * original design keyed each threshold by the grade item's DATABASE id - which Moodle recreates
 * whenever the activity's grade settings are edited or the course is restored. A recreated id
 * orphaned the stored key: both the reading (the card showed no threshold) and the evaluation (the
 * rule silently never fired again) reconstructed the key from the CURRENT id and missed the stored
 * one. The itemnumber (0, 1, ... within a module) is stable across those events; keying by it is the
 * fix.
 *
 * This class is the single reader that both evaluate() and the card description go through, so they
 * agree, and it tolerates BOTH shapes:
 *  - NEW: each entry carries its 'itemnumber' (written by save_condition after this fix).
 *  - LEGACY: entries carry only the volatile 'gradeitem' id (rows saved before the fix). Their
 *    itemnumber is unknowable from the dead id, so they are mapped BY POSITION: distinct stored ids
 *    in first-seen order line up with the activity's itemnumbers in order. For the overwhelmingly
 *    common single-grade-item activity this is exact; for a multi-item activity with legacy data it
 *    is the best available reconstruction and still beats a permanently dead condition.
 *
 * Pure and free of DB access so both callers share one tested definition.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_condition_thresholds {
    /**
     * Map the stored conditions to itemnumber => {gradegte, gradelt} threshold objects.
     *
     * @param object|array|null $stored The gradeitemsconditions value decoded from the params JSON.
     * @param int[] $itemnumbers The activity's grade-item itemnumbers, in itemnumber order.
     * @return array<int, \stdClass> itemnumber => stdClass with optional ->gradegte and ->gradelt,
     *         each an object carrying at least ->value. Only itemnumbers with a threshold appear.
     */
    public static function by_itemnumber($stored, array $itemnumbers): array {
        $entries = self::normalise_entries($stored);
        if (empty($entries)) {
            return [];
        }

        $result = [];

        // NEW shape wins whenever ANY entry carries an itemnumber: mixed data (a legacy row later
        // re-saved) still resolves, because a re-save rewrites every entry with its itemnumber.
        $hasitemnumber = false;
        foreach ($entries as $entry) {
            if ($entry->itemnumber !== null) {
                $hasitemnumber = true;
                break;
            }
        }

        if ($hasitemnumber) {
            foreach ($entries as $entry) {
                if ($entry->itemnumber !== null) {
                    self::place($result, (int) $entry->itemnumber, $entry);
                }
            }
            return $result;
        }

        // LEGACY shape: recover the itemnumber by position from the stored (now-dead) ids.
        $idorder = [];
        foreach ($entries as $entry) {
            if ($entry->gradeitem !== null && !in_array($entry->gradeitem, $idorder, true)) {
                $idorder[] = $entry->gradeitem;
            }
        }
        $idtoitemnumber = [];
        foreach (array_values($idorder) as $position => $id) {
            if (array_key_exists($position, $itemnumbers)) {
                $idtoitemnumber[$id] = (int) $itemnumbers[$position];
            }
        }
        foreach ($entries as $entry) {
            if ($entry->gradeitem !== null && isset($idtoitemnumber[$entry->gradeitem])) {
                self::place($result, $idtoitemnumber[$entry->gradeitem], $entry);
            }
        }
        return $result;
    }

    /**
     * Flatten the stored conditions object/array into a list of threshold entries.
     *
     * @param object|array|null $stored
     * @return \stdClass[] Each with ->condition, ->value, ->itemnumber (int|null), ->gradeitem (int|null).
     */
    private static function normalise_entries($stored): array {
        if (is_object($stored)) {
            $stored = get_object_vars($stored);
        }
        if (!is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $entry) {
            $entry = (array) $entry;
            if (!isset($entry['condition']) || !array_key_exists('value', $entry)) {
                continue;
            }
            $entries[] = (object) [
                'condition' => (string) $entry['condition'],
                'value' => $entry['value'],
                'itemnumber' => (isset($entry['itemnumber']) && $entry['itemnumber'] !== '')
                    ? (int) $entry['itemnumber']
                    : null,
                'gradeitem' => (isset($entry['gradeitem']) && $entry['gradeitem'] !== '')
                    ? (int) $entry['gradeitem']
                    : null,
            ];
        }
        return $entries;
    }

    /**
     * Attach one entry to its itemnumber slot under the right operator key.
     *
     * @param array<int, \stdClass> $result Passed by reference, accumulated.
     * @param int $itemnumber
     * @param \stdClass $entry
     * @return void
     */
    private static function place(array &$result, int $itemnumber, \stdClass $entry): void {
        if (!isset($result[$itemnumber])) {
            $result[$itemnumber] = (object) ['gradegte' => null, 'gradelt' => null];
        }
        if ($entry->condition === 'gradegte') {
            $result[$itemnumber]->gradegte = $entry;
        } else if ($entry->condition === 'gradelt') {
            $result[$itemnumber]->gradelt = $entry;
        }
    }
}
