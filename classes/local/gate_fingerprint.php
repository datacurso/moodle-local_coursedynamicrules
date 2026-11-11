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
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gate_fingerprint {
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
     * The gates this plugin wrote on the modules of a course, as the backup records them.
     *
     * Only nodes owned_user_nodes() accepts (marked, not adopted) and only markers naming an action of
     * this course's rules are recorded: anything else the restore could not map anyway.
     *
     * @param int $courseid The course being backed up.
     * @return array List of ['id' => int, 'cmid' => int, 'actionid' => int, 'fingerprint' => string].
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
            foreach (enableactivity_action::owned_user_nodes($root) as $node) {
                $actionid = enableactivity_action::action_id_of($node);
                if ($actionid === null || !isset($actionids[$actionid])) {
                    continue;
                }
                $rows[] = [
                    'id' => count($rows) + 1,
                    'cmid' => (int) $module->id,
                    'actionid' => $actionid,
                    'fingerprint' => self::of($actionid, owned_gate_eraser::userids_of($node)),
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
     * @param string $fingerprint The fingerprint the backup recorded.
     * @return \stdClass[] The matching nodes, by reference into $root, in tree order.
     */
    public static function matching_nodes(\stdClass $root, int $sourceactionid, int $restoredactionid, string $fingerprint): array {
        $owned = enableactivity_action::owned_user_nodes($root);

        $matches = [];
        foreach (self::user_nodes($root) as $node) {
            $actionid = enableactivity_action::action_id_of($node);
            $candidate = $actionid === null || ($actionid === $restoredactionid && in_array($node, $owned, true));
            if ($candidate && hash_equals($fingerprint, self::of($sourceactionid, owned_gate_eraser::userids_of($node)))) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    /**
     * Every user node of a tree, at any depth, in tree order.
     *
     * @param object $root A decoded availability tree (the root, or any subtree).
     * @return \stdClass[]
     */
    private static function user_nodes(object $root): array {
        if (isset($root->type)) {
            return $root->type === 'user' ? [$root] : [];
        }
        $found = [];
        foreach ((array) ($root->c ?? []) as $child) {
            if (is_object($child)) {
                $found = array_merge($found, self::user_nodes($child));
            }
        }

        return $found;
    }
}
