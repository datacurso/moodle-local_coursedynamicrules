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

namespace local_coursedynamicrules\local;

use local_coursedynamicrules\action\enableactivity\enableactivity_action;

/**
 * One-time reconciliation of the gates a restore could only deduce.
 *
 * A backup made before 1.8.13 records no gate fingerprint, so its restore can only re-adopt the
 * stripped markers, flagged as deduced, which the privacy provider refuses. This pass decides, per
 * module listed by an enable-activity action of the course, whether the deduction can be confirmed
 * with the evidence the course still holds (CDR-PRIV-002-R2). A node is promoted to written
 * (enableactivity_action::mark_node()) only when all of these hold:
 *
 * - exactly one action listing the module has no attributed node on it, and its rule is active;
 * - the module carries exactly one node the restore deduced for that action (is_adopted(), with that
 *   action's marker);
 * - that node has the shape apply_availability() writes: a direct child of an AND root, hidden when
 *   not met.
 *
 * An unmarked node is never promoted, whatever its shape: it may be a teacher's restriction, and
 * nothing on the module says otherwise (internal security review, 2026-11-20). When a node is
 * promoted, only the inferred notes of that action on the module are removed. When nothing qualifies,
 * nothing is marked; in a run queued by a restore every user the candidate nodes list gets an
 * "ambiguous" note in place of the action's "inferred" one, and in a run over a whole course (the
 * upgrade) only modules that carry a deduced node are noted, so the run never reaches further than
 * the restore's own deductions. Deduced markers are left as they are: the engine needs them.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_reconciliation {
    /**
     * Reconcile the modules listed by the enable-activity actions of a course.
     *
     * @param int $courseid The course.
     * @param int[]|null $actionids Only these actions (the ones a restore created), or null for all of the course.
     * @return int The number of nodes attributed.
     */
    public static function run(int $courseid, ?array $actionids = null): int {
        global $DB;

        [$listing, $active] = self::actions_by_module($courseid, $actionids);
        $attributed = 0;
        foreach ($listing as $cmid => $listedby) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $courseid], 'id, availability');
            $root = $cm ? json_decode((string) $cm->availability) : null;
            if (!$root instanceof \stdClass) {
                continue;
            }
            $listedby = array_values(array_unique($listedby));
            if (self::reconcile_module($courseid, $cm, $root, $listedby, $active, $actionids !== null)) {
                $attributed++;
            }
        }
        if ($attributed > 0) {
            rebuild_course_cache($courseid, true);
        }

        return $attributed;
    }

    /**
     * Reconcile one module.
     *
     * @param int $courseid The course.
     * @param \stdClass $cm The module row (id, availability).
     * @param \stdClass $root Its decoded tree.
     * @param int[] $listedby The enable-activity actions listing it.
     * @param bool[] $active Whether each action's rule is active, by action id.
     * @param bool $restorescoped Whether the run was queued by a restore for its own actions.
     * @return bool Whether a node was attributed.
     */
    private static function reconcile_module(
        int $courseid,
        \stdClass $cm,
        \stdClass $root,
        array $listedby,
        array $active,
        bool $restorescoped
    ): bool {
        global $DB;

        $owned = enableactivity_action::owned_user_nodes($root);
        $ownedby = array_map([enableactivity_action::class, 'action_id_of'], $owned);
        $pending = array_values(array_diff($listedby, $ownedby));
        // A deduction an attributed node has since superseded is no longer worth telling anyone about.
        restore_note::forget_inferred((int) $cm->id, array_values(array_intersect($listedby, $ownedby)));
        if ($pending === []) {
            return false;
        }

        $candidates = [];
        $adopted = [];
        foreach (gate_fingerprint::positions($root) as $located) {
            $node = $located['node'];
            $actionid = enableactivity_action::action_id_of($node);
            if (in_array($node, $owned, true) || ($actionid !== null && !in_array($actionid, $pending, true))) {
                continue;
            }
            $candidates[] = $node;
            if ($actionid !== null && enableactivity_action::is_adopted($node)) {
                $adopted[$actionid][] = $node;
            }
        }

        if (count($pending) === 1 && !empty($active[$pending[0]])) {
            $mine = $adopted[$pending[0]] ?? [];
            if (count($mine) === 1 && self::has_plugin_shape($root, $mine[0])) {
                enableactivity_action::mark_node($mine[0], $pending[0]);
                $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cm->id]);
                restore_note::forget_inferred((int) $cm->id, [$pending[0]]);
                return true;
            }
        }

        if (!$restorescoped && $adopted === []) {
            return false;
        }
        restore_note::forget_inferred((int) $cm->id, $pending);
        foreach ($candidates as $node) {
            restore_note::record(
                $courseid,
                (int) $cm->id,
                count($pending) === 1 ? $pending[0] : null,
                owned_gate_eraser::userids_of($node),
                restore_note::REASON_AMBIGUOUS
            );
        }

        return false;
    }

    /**
     * Whether a node sits where apply_availability() puts its gate: a direct child of an AND root,
     * hidden when not met.
     *
     * @param \stdClass $root The decoded tree.
     * @param \stdClass $node The node.
     * @return bool
     */
    private static function has_plugin_shape(\stdClass $root, \stdClass $node): bool {
        if (($root->op ?? '&') !== '&') {
            return false;
        }
        $children = array_values((array) ($root->c ?? []));
        $index = array_search($node, $children, true);
        if ($index === false) {
            return false;
        }
        $showc = is_array($root->showc ?? null) ? array_values($root->showc) : [];

        return empty($showc[$index]);
    }

    /**
     * The modules of the course each enable-activity action lists, and whether each action's rule is active.
     *
     * @param int $courseid The course.
     * @param int[]|null $actionids Only these actions, or null for all of the course.
     * @return array [array<int, int[]> cmid => action ids, array<int, bool> action id => active].
     */
    private static function actions_by_module(int $courseid, ?array $actionids): array {
        global $DB;

        $sql = "SELECT a.id, a.params, r.active
                  FROM {local_coursedynamicrules_action} a
                  JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                 WHERE r.courseid = :courseid AND a.actiontype = :actiontype";
        $params = ['courseid' => $courseid, 'actiontype' => 'enableactivity'];
        if ($actionids !== null) {
            if ($actionids === []) {
                return [[], []];
            }
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $actionids), SQL_PARAMS_NAMED);
            $sql .= " AND a.id $insql";
            $params += $inparams;
        }

        $listing = [];
        $active = [];
        foreach ($DB->get_records_sql($sql, $params) as $action) {
            $active[(int) $action->id] = !empty($action->active);
            $decoded = json_decode((string) $action->params);
            foreach ((array) ($decoded->coursemodules ?? []) as $coursemodule) {
                $cmid = (int) (is_object($coursemodule) ? ($coursemodule->id ?? 0) : $coursemodule);
                if ($cmid > 0) {
                    $listing[$cmid][] = (int) $action->id;
                }
            }
        }

        return [$listing, $active];
    }
}
