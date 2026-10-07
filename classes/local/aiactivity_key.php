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

/**
 * The idempotency key an AI-generated activity carries in its course module ID number.
 *
 * One AI activity exists per (action, student): the key is what proves a module is that activity.
 * It is an HMAC keyed with the site identifier, so the student's id is not written in clear into
 * a column teachers see and the gradebook uses.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aiactivity_key {
    /** @var string Prefix of every key, so a restore can narrow its scan to the modules that may carry one. */
    public const PREFIX = 'cdrai_';

    /** @var int Hex characters of the HMAC kept in the key (46 characters in all, the column holds 100). */
    private const HASH_LENGTH = 40;

    /**
     * The key of the activity an action generates for one student on this site.
     *
     * @param int $actionid The createaiactivity action.
     * @param int $userid The student.
     * @return string
     */
    public static function for_action_user(int $actionid, int $userid): string {
        $hmac = hash_hmac('sha256', "{$actionid}:{$userid}", get_site_identifier());

        return self::PREFIX . substr($hmac, 0, self::HASH_LENGTH);
    }

    /**
     * The key a restored activity must carry, or null when its key is not one of the restored actions'.
     *
     * The key is derived from the action id, and a restore gives the action a new one. The student
     * cannot be read back out of an HMAC, so each candidate (the user ids the activity's restriction
     * lists) is tried against each source action until one reproduces the key.
     *
     * @param string $idnumber The restored module's ID number.
     * @param int[] $userids Candidate students.
     * @param int[] $actionidmap Source action id => restored action id.
     * @return string|null The key for the restored action, or null when nothing matches.
     */
    public static function rekeyed_for_restore(string $idnumber, array $userids, array $actionidmap): ?string {
        $match = self::attribution($idnumber, array_keys($actionidmap), $userids);
        if ($match === null) {
            return null;
        }

        return self::for_action_user((int) $actionidmap[$match[0]], $match[1]);
    }

    /**
     * The action and student a module's ID number is the key of, or null when it is no such key.
     *
     * The student cannot be read back out of an HMAC, so each candidate pair is tried until one
     * reproduces the key. A match proves the attribution: nobody without the site identifier can
     * forge a key for a pair.
     *
     * @param string $idnumber The module's ID number.
     * @param int[] $actionids Candidate createaiactivity actions.
     * @param int[] $userids Candidate students.
     * @return int[]|null [actionid, userid], or null when no pair reproduces the key.
     */
    public static function attribution(string $idnumber, array $actionids, array $userids): ?array {
        if (strpos($idnumber, self::PREFIX) !== 0) {
            return null;
        }
        foreach ($actionids as $actionid) {
            foreach ($userids as $userid) {
                if (hash_equals(self::for_action_user((int) $actionid, (int) $userid), $idnumber)) {
                    return [(int) $actionid, (int) $userid];
                }
            }
        }

        return null;
    }

    /**
     * Every user id listed by a user restriction anywhere in a decoded availability tree.
     *
     * Marked or not: a restore can re-encode a tree and drop the ownership marker, while the key
     * it is checked against does not depend on it.
     *
     * @param object $root A decoded availability tree (the root, or any subtree).
     * @return int[]
     */
    public static function user_ids_in_tree(object $root): array {
        // Same walk as enableactivity_action::owned_user_nodes(): a node with a type is a leaf.
        if (isset($root->type)) {
            return $root->type === 'user' ? array_map('intval', (array) ($root->userids ?? [])) : [];
        }

        $userids = [];
        foreach ((array) ($root->c ?? []) as $child) {
            if (is_object($child)) {
                $userids = array_merge($userids, self::user_ids_in_tree($child));
            }
        }

        return array_values(array_unique($userids));
    }
}
