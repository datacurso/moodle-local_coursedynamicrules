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
 * Records which availability nodes this plugin wrote, so a restore can tell them apart again.
 *
 * Core re-encodes a module's whole availability tree on restore whenever a sibling condition changed,
 * and availability_user::save() writes only the type and the user ids, so the ownership marker is
 * lost. The backup therefore carries, for every node the plugin wrote (marked and not merely adopted),
 * the module, the action and a fingerprint: a SHA-256 of the action id and the sorted user ids the
 * node lists. Neither depends on the site, so it verifies on any site the course is restored to.
 *
 * On restore the fingerprint is matched against the restored module's nodes that carry no marker, or
 * the restored action's own. Exactly one match is the node the plugin wrote; two or more identical
 * nodes - a teacher's restriction listing the same students - are ambiguous and claimed by nobody.
 *
 * The version 2 fingerprint (CDR-PRIV-002-R2) closes most of that ambiguity: it also covers the node's
 * position in the tree (the operator and child index of every level down to it, and its own show flag)
 * and a summary of the conditions beside it (their types and the parameters a restore does not
 * rewrite, sorted). A teacher's copy listing the same students sits elsewhere in the tree, so it no
 * longer matches. Nothing personal enters the summary: no user ids, no profile values, no ids of
 * other objects. Backups carry both versions, so older plugin versions keep reading the first one,
 * and the restore still accepts backups that carry only the first one.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gate_fingerprint {
    /**
     * The most nodes a tree may have for any of its nodes to be matched (internal security review,
     * 2026-11-20). The restriction editor builds trees of a handful of conditions; a tree beyond this
     * is not one the plugin wrote into, and walking it would only cost time. Beyond it nothing is
     * fingerprinted by the backup and nothing is matched or reconciled by the restore.
     */
    public const MAX_TREE_NODES = 500;

    /**
     * The fingerprint of a node listing the given users, written by the given action.
     *
     * @param int $actionid The action that wrote the node.
     * @param int[] $userids The user ids the node lists, in any order.
     * @return string A SHA-256 hex digest.
     */
    public static function of(int $actionid, array $userids): string {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        sort($userids);

        return hash('sha256', $actionid . ':' . implode(',', $userids));
    }

    /**
     * The version 2 fingerprint of a node: the action, the ids it lists and where it sits in its tree.
     *
     * @param int $actionid The action that wrote the node.
     * @param int[] $userids The user ids the node lists, in any order.
     * @param string $position The node's position, as positions() describes it.
     * @return string A SHA-256 hex digest.
     */
    public static function of_v2(int $actionid, array $userids, string $position): string {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        sort($userids);

        return hash('sha256', 'v2:' . $actionid . ':' . implode(',', $userids) . ':' . $position);
    }

    /**
     * Every user node of a tree with its position, in tree order.
     *
     * The position is the path down to the node - each level's operator and the node's child index
     * in it, and its own show flag - followed by the sorted summaries of its siblings. A restore keeps
     * all of that in the ordinary case: core re-encodes the tree in order and rewrites only ids and
     * dates. It does not keep it when it drops a condition: a group or grouping condition is removed
     * when groups are not restored (core_availability\tree::update_after_restore()), which shifts the
     * indexes and changes the siblings. Then the version 2 fingerprint simply does not match, and the
     * restore claims nothing - it never claims a different node.
     *
     * A tree with more than MAX_TREE_NODES nodes yields no position at all, so no fingerprint matches in
     * it and nothing is claimed there.
     *
     * @param object $root A decoded availability tree.
     * @return array List of ['node' => \stdClass, 'position' => string].
     */
    public static function positions(object $root): array {
        $budget = self::MAX_TREE_NODES;
        if (!self::within_cap($root, $budget)) {
            return [];
        }

        return self::positions_below($root, '');
    }

    /**
     * Whether a tree has at most the remaining budget of nodes; the budget is spent as nodes are counted.
     *
     * @param object $node A decoded node or subtree.
     * @param int $budget Nodes still allowed.
     * @return bool
     */
    private static function within_cap(object $node, int &$budget): bool {
        if (--$budget < 0) {
            return false;
        }
        if (isset($node->type)) {
            return true;
        }
        foreach ((array) ($node->c ?? []) as $child) {
            if (is_object($child) && !self::within_cap($child, $budget)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The positions of the user nodes below one level of a tree.
     *
     * The sibling summary of every user node of a level is the same - all the level's summaries but one
     * 'user' - so it is computed once per level.
     *
     * @param object $root The level.
     * @param string $path The path down to it.
     * @return array List of ['node' => \stdClass, 'position' => string].
     */
    private static function positions_below(object $root, string $path): array {
        if (isset($root->type)) {
            return [];
        }
        $op = (string) ($root->op ?? '&');
        $children = array_values((array) ($root->c ?? []));
        $showc = is_array($root->showc ?? null) ? array_values($root->showc) : [];

        $summaries = [];
        foreach ($children as $child) {
            if (is_object($child)) {
                $summaries[] = self::summary_of($child);
            }
        }
        sort($summaries);
        $own = array_search('user', $summaries, true);
        if ($own !== false) {
            unset($summaries[$own]);
        }
        $siblings = implode(',', $summaries);

        $found = [];
        foreach ($children as $index => $child) {
            if (!is_object($child)) {
                continue;
            }
            $step = $path . '/' . $op . $index;
            if (!isset($child->type)) {
                $found = array_merge($found, self::positions_below($child, $step));
                continue;
            }
            if ($child->type !== 'user') {
                continue;
            }
            $shown = array_key_exists($index, $showc) ? (int) (bool) $showc[$index] : (int) !empty($root->show);
            $found[] = ['node' => $child, 'position' => $step . ':h' . $shown . '|' . $siblings];
        }

        return $found;
    }

    /**
     * A summary of a condition that names nobody and survives a restore.
     *
     * Only what a restore leaves as it is enters it: the type, and for the core conditions the
     * parameters that are not ids or dates. User ids, profile values and the ids of other objects
     * stay out.
     *
     * @param \stdClass $condition A decoded condition, or a nested subtree.
     * @return string
     */
    private static function summary_of(\stdClass $condition): string {
        if (!isset($condition->type)) {
            return 'tree:' . (string) ($condition->op ?? '&') . ':' . count((array) ($condition->c ?? []));
        }
        $type = (string) $condition->type;
        switch ($type) {
            case 'completion':
                return 'completion:e=' . (int) ($condition->e ?? 0);
            case 'date':
                return 'date:d=' . (string) ($condition->d ?? '');
            case 'grade':
                return 'grade:min=' . (isset($condition->min) ? (float) $condition->min : '')
                    . ':max=' . (isset($condition->max) ? (float) $condition->max : '');
            case 'profile':
                return 'profile:' . (string) ($condition->sf ?? '') . ':' . (string) ($condition->cf ?? '')
                    . ':' . (string) ($condition->op ?? '');
            default:
                return $type;
        }
    }

    /**
     * The gates this plugin wrote on the modules of a course, as the backup records them.
     *
     * Only nodes owned_user_nodes() accepts (marked, not adopted) and only markers naming an action of
     * this course's rules are recorded: anything else the restore could not map anyway.
     *
     * @param int $courseid The course being backed up.
     * @return array List of ['id' => int, 'cmid' => int, 'actionid' => int, 'fingerprint' => string,
     *               'fingerprint2' => string].
     */
    public static function gates_of_course(int $courseid): array {
        global $DB;

        $sql = "SELECT a.id
                  FROM {local_coursedynamicrules_action} a
                  JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                 WHERE r.courseid = :courseid";
        $actionids = array_flip(array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid])));
        if ($actionids === []) {
            return [];
        }

        $like = $DB->sql_like('availability', ':marker');
        $modules = $DB->get_recordset_select(
            'course_modules',
            "course = :courseid AND $like",
            ['courseid' => $courseid, 'marker' => '%' . $DB->sql_like_escape(enableactivity_action::marker_prefix()) . '%'],
            'id',
            'id, availability'
        );

        $rows = [];
        foreach ($modules as $module) {
            $root = json_decode((string) $module->availability);
            if (!$root instanceof \stdClass) {
                continue;
            }
            $owned = enableactivity_action::owned_user_nodes($root);
            foreach (self::positions($root) as $located) {
                $node = $located['node'];
                $actionid = enableactivity_action::action_id_of($node);
                if ($actionid === null || !isset($actionids[$actionid]) || !in_array($node, $owned, true)) {
                    continue;
                }
                $userids = owned_gate_eraser::userids_of($node);
                $rows[] = [
                    'id' => count($rows) + 1,
                    'cmid' => (int) $module->id,
                    'actionid' => $actionid,
                    'fingerprint' => self::of($actionid, $userids),
                    'fingerprint2' => self::of_v2($actionid, $userids, $located['position']),
                ];
            }
        }
        $modules->close();

        return $rows;
    }

    /**
     * The nodes of a restored tree that match a gate recorded by the backup.
     *
     * A candidate carries no marker at all (core stripped it), or carries the restored action's own
     * marker (it survived and was renamed). A node marked for another action, or flagged as adopted,
     * is somebody else's or a deduction, and never a candidate.
     *
     * @param \stdClass $root The decoded restored tree.
     * @param int $sourceactionid The action id the backup recorded, which the fingerprint was made with.
     * @param int $restoredactionid The id that action was restored under.
     * @param string $fingerprint The version 1 fingerprint the backup recorded.
     * @param string $fingerprint2 The version 2 fingerprint, empty when the backup carries none; when
     *        present it is the one compared.
     * @return \stdClass[] The matching nodes, by reference into $root, in tree order.
     */
    public static function matching_nodes(
        \stdClass $root,
        int $sourceactionid,
        int $restoredactionid,
        string $fingerprint,
        string $fingerprint2 = ''
    ): array {
        $owned = enableactivity_action::owned_user_nodes($root);

        $matches = [];
        foreach (self::positions($root) as $located) {
            $node = $located['node'];
            $actionid = enableactivity_action::action_id_of($node);
            $candidate = $actionid === null || ($actionid === $restoredactionid && in_array($node, $owned, true));
            if (!$candidate) {
                continue;
            }
            $userids = owned_gate_eraser::userids_of($node);
            $matched = $fingerprint2 !== ''
                ? hash_equals($fingerprint2, self::of_v2($sourceactionid, $userids, $located['position']))
                : hash_equals($fingerprint, self::of($sourceactionid, $userids));
            if ($matched) {
                $matches[] = $node;
            }
        }

        return $matches;
    }
}
