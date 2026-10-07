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

use local_coursedynamicrules\action\createaiactivity\createaiactivity_action;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

/**
 * Finds and erases the user ids this plugin wrote into {course_modules}.availability.
 *
 * Shared by the privacy provider and the user_deleted observer, so a data-subject request and an
 * ordinary account deletion clean exactly the same nodes. A node is this plugin's when:
 *
 * 1. it carries the plugin's marker and was not merely adopted by a restore
 *    (enableactivity_action::owned_user_nodes()), whatever action type wrote it; or
 * 2. the module's ID number is the key createaiactivity_action gives the activity it generates
 *    (aiactivity_key), the key verifies against one of the course's AI actions and one student, no
 *    marked node of that action survives, and exactly ONE unmarked user node lists exactly that
 *    student - the shape the action writes. The key proves the attribution, so this is not the
 *    deduction a restore's re-adoption makes. Saving the module settings form drops the marker and
 *    keeps the ID number, which is why this second rule exists.
 *
 * When the key verifies but two or more unmarked nodes list exactly that student, the module is
 * AMBIGUOUS: nothing on it is edited, and the caller reports it.
 *
 * The key itself is derived from the student's id, so erasing that student clears it too, on the
 * module and on its grade item when that still holds it (createaiactivity_action::set_generated_module_key()).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class owned_gate_eraser {
    /**
     * The modules holding the user's id in a node of ours, or their key in the ID number.
     *
     * The marked nodes are found by narrowing on the marker and deciding on the decoded tree: the
     * ids live inside a JSON tree, so a LIKE on "24" would match "124" and "240" just as happily.
     * Streamed with a recordset, because the LIKE has a leading wildcard on a TEXT column and the
     * candidate set is bounded only by how many modules the site has.
     *
     * The AI activities are found through their key instead, which does not depend on the marker:
     * one key per createaiactivity action on the site, looked up through core's ID number index.
     *
     * Reads no user record, so it works for a user who has already been deleted.
     *
     * @param int $userid The user.
     * @return int[] Course module ids, possibly empty.
     */
    public static function modules_holding(int $userid): array {
        global $DB;

        $like = $DB->sql_like('availability', ':marker');
        $params = ['marker' => '%' . $DB->sql_like_escape(enableactivity_action::marker_prefix()) . '%'];

        $cmids = [];
        $rs = $DB->get_recordset_select('course_modules', $like, $params, '', 'id, availability');
        foreach ($rs as $cm) {
            $root = self::decode($cm->availability);
            if ($root === null) {
                continue;
            }
            foreach (enableactivity_action::owned_user_nodes($root) as $node) {
                if (in_array($userid, self::userids_of($node), true)) {
                    $cmids[$cm->id] = (int) $cm->id;
                    break;
                }
            }
        }
        $rs->close();

        foreach (self::modules_keyed_for($userid) as $cmid) {
            $cmids[$cmid] = $cmid;
        }

        return array_values($cmids);
    }

    /**
     * The users a module's nodes of ours list, plus the student its AI key names.
     *
     * @param int $cmid The course module.
     * @return int[] User ids, possibly empty.
     */
    public static function users_in_module(int $cmid): array {
        $cm = self::course_module($cmid);
        if ($cm === null) {
            return [];
        }
        $claim = self::claim($cm, self::decode($cm->availability), []);

        $userids = [];
        foreach ($claim->nodes as $entry) {
            $userids = array_merge($userids, self::userids_of($entry->node));
        }
        if ($claim->attribution !== null) {
            $userids[] = $claim->attribution[1];
        }

        return array_values(array_unique($userids));
    }

    /**
     * The action behind each node of ours on a module that lists the user.
     *
     * An ambiguous AI node is not one of them: nothing proves which of the candidates is ours.
     *
     * The AI key is reported whenever it is the user's, ambiguous or not: it is derived from their id.
     *
     * @param int $cmid The course module.
     * @param int $userid The user.
     * @return array|null ['courseid' => int, 'actionids' => (int|null)[], 'aikey' => string|null], or null
     *     when the module is gone. aikey is the module's ID number when it is the user's AI activity key.
     */
    public static function holdings(int $cmid, int $userid): ?array {
        $cm = self::course_module($cmid);
        if ($cm === null) {
            return null;
        }
        $claim = self::claim($cm, self::decode($cm->availability), [$userid]);

        $actionids = [];
        foreach ($claim->nodes as $entry) {
            if (in_array($userid, self::userids_of($entry->node), true)) {
                $actionids[] = $entry->actionid;
            }
        }

        $aikey = ($claim->attribution !== null && $claim->attribution[1] === $userid) ? (string) $cm->idnumber : null;

        return ['courseid' => (int) $cm->course, 'actionids' => $actionids, 'aikey' => $aikey];
    }

    /**
     * Erase the user from every node of ours on the site, as an account deletion does.
     *
     * @param int $userid The deleted user.
     * @return array ['courseids' => int[] courses to invalidate, 'ambiguous' => int[] modules left alone].
     */
    public static function erase_user(int $userid): array {
        $courseids = [];
        $ambiguous = [];
        foreach (self::modules_holding($userid) as $cmid) {
            $result = self::erase($cmid, [$userid]);
            if ($result['courseid'] !== null) {
                $courseids[$result['courseid']] = $result['courseid'];
            }
            if ($result['ambiguous']) {
                $ambiguous[] = $cmid;
            }
        }

        return ['courseids' => array_values($courseids), 'ambiguous' => $ambiguous];
    }

    /**
     * Rewrite a module's nodes of ours without the given users (or without anyone), keeping every node.
     *
     * Only nodes this plugin owns are touched. Every other node - and every other key of our own
     * nodes, the marker first among them - is written back with its keys and values unchanged,
     * because the tree is edited as decoded JSON and never re-serialised through the availability
     * API, which would rebuild each node from its own condition class and drop the marker. Not
     * byte-identical, though: json_encode() re-escapes a forward slash and a literal non-ASCII
     * character, and normalises a float. Nothing in core compares the stored string, and a tree core
     * itself wrote is already in that form, so the difference is invisible - but the claim is stated
     * as what it is rather than as an absolute.
     *
     * The surviving list is re-indexed: a gapped PHP array encodes as a JSON object, which
     * availability_user rejects with a TypeError.
     *
     * When the AI key names one of the users being erased, the key is cleared as well. When that key
     * is ambiguous (see the class docblock) the module is left exactly as it is and reported.
     *
     * Returns the course that must be invalidated rather than invalidating it, so a caller holding
     * several modules of one course can do it once. The tree students are evaluated against comes
     * from modinfo, so a write nobody invalidates erases the id in the database and leaves the
     * student's access exactly as it was until some unrelated edit happens to rebuild it - the
     * caller owes that invalidation, it is not optional.
     *
     * @param int $cmid The course module.
     * @param int[]|null $userids The ids to remove, or null to remove every id.
     * @return array ['courseid' => int|null course to invalidate, null when nothing changed,
     *               'ambiguous' => bool whether an ambiguous AI module was left alone].
     */
    public static function erase(int $cmid, ?array $userids): array {
        global $DB;

        $cm = self::course_module($cmid);
        if ($cm === null) {
            return ['courseid' => null, 'ambiguous' => false];
        }
        $root = self::decode($cm->availability);
        $claim = self::claim($cm, $root, $userids ?? []);

        // The key, and the node only it attributes, belong to one student: they are erased only
        // when that student is.
        $keyed = $claim->attribution !== null && ($userids === null || in_array($claim->attribution[1], $userids, true));
        if ($keyed && $claim->ambiguous) {
            return ['courseid' => null, 'ambiguous' => true];
        }

        $changed = false;
        foreach ($claim->nodes as $entry) {
            $node = $entry->node;
            $before = self::userids_of($node);
            $after = $userids === null
                ? []
                : array_values(array_filter($before, static function (int $id) use ($userids): bool {
                    return !in_array($id, $userids, true);
                }));
            if ($after !== $before) {
                // Written back as the plural key alone, and the singular one dropped. $before was
                // the list CORE reads - both keys folded together - so $after is the complete set of
                // survivors and belongs in one place. Leaving the singular key alone would take the
                // student out of the list this class looked at and leave them in the list core
                // evaluates, which is an erasure that erases nothing while reporting success. This
                // is also the exact shape availability_user::save() writes, so the node is left in
                // the form core itself would have produced.
                $node->userids = $after;
                unset($node->userid);
                $changed = true;
            }
        }

        if ($changed) {
            // Refusing to write beats writing false. json_encode() returns false rather than
            // throwing, and an empty availability column means NO restrictions at all - the activity
            // would open to the whole course, which is the single outcome this class exists to
            // prevent. The input came from json_decode() of a stored tree, so this is not expected;
            // it is guarded because the cost of being wrong is the harm the design is built around.
            // Raising is the only channel core offers a privacy provider: the exception is caught
            // per component and mailed to the data protection officers
            // (tool_dataprivacy\manager_observer), which is a person learning about it instead of a
            // column being blanked in silence.
            $encoded = json_encode($root);
            if (!is_string($encoded)) {
                throw new \coding_exception(
                    'local_coursedynamicrules: refusing to write an unencodable availability tree',
                    'course module ' . $cm->id . ': ' . json_last_error_msg()
                );
            }
            $DB->set_field('course_modules', 'availability', $encoded, ['id' => $cm->id]);
        }

        if ($keyed) {
            // The key is a pseudonym of the student's id. Clearing it also re-arms the action for
            // that student, which only matters while the account still exists and still qualifies.
            createaiactivity_action::set_generated_module_key((int) $cm->id, '');
            $changed = true;
        }

        return ['courseid' => $changed ? (int) $cm->course : null, 'ambiguous' => false];
    }

    /**
     * The unmarked user nodes of a tree that list exactly one student: the shape the AI action writes.
     *
     * @param \stdClass $root A decoded availability tree.
     * @param int $userid The student.
     * @return \stdClass[] The nodes, in tree order.
     */
    public static function generated_nodes(\stdClass $root, int $userid): array {
        $found = [];
        foreach (self::user_nodes($root) as $node) {
            if (enableactivity_action::action_id_of($node) === null && self::userids_of($node) === [$userid]) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * The user ids a node lists, as integers: the stored list can mix strings and integers.
     *
     * A node whose list is not a list is corrupt. It is read as empty and left exactly as it is,
     * rather than throwing: an erasure request must not die half-way through on one bad row.
     *
     * @param \stdClass $node A user restriction node.
     * @return int[]
     */
    public static function userids_of(\stdClass $node): array {
        $userids = $node->userids ?? null;
        // The json_decode() call hands back a stdClass, not an array, whenever the stored list has gaps or
        // string keys - which is exactly what a gapped PHP array encodes to. The ids are still there
        // and they are still personal data, so they are recovered rather than dropped. Dropping them
        // would leave the module out of the contextlist, and the contextlist is the attestation:
        // tool_dataprivacy would tell the student their data was erased while these ids stayed.
        if (is_object($userids)) {
            $userids = (array) $userids;
        }
        $raw = is_array($userids) ? array_values($userids) : [];

        // And the SINGULAR key, which availability_user has always honoured and still does: its
        // constructor pushes $structure->userid onto the list it evaluates
        // (availability/condition/user/classes/condition.php). A node carrying it restricts the
        // activity to that student exactly as a list would, and it can carry this plugin's marker,
        // because a node is stamped by its TYPE and nothing about the stamping inspects the shape
        // inside it. Reading only the plural key would therefore leave a student this plugin gated
        // outside both the export and the erasure, while the deletion request reported success.
        if (isset($node->userid)) {
            $raw[] = $node->userid;
        }

        // A value names a student when core would let that student in because of it, and core
        // decides with a LOOSE in_array() over the values exactly as stored. So every numeric way of
        // writing an id counts - "501", "0501", " 501", "+501", "501.0" all get that student in, and
        // ids are stored as strings far more often than as integers: 33 of the 34 on the reference
        // site on 2026-09-17. Accepting only plain digits was drafted here and rejected, because it
        // would have stopped claiming students core keeps letting in, and their data would have
        // stopped being exported and erased while the request was reported as completed.
        //
        // Casting whatever arrives is the opposite error and is what this replaced: (int)"501abc" is
        // 501, a student core never matches, and (int)true is 1 - which made the guest a data
        // subject and, through the rewrite below, wrote the guest a real grant into the activity.
        //
        // Two departures from core are deliberate and are the only ones measured over seventeen
        // stored forms: a stored `true` or object compares equal to somebody under core's loose
        // comparison, so core does admit them, but neither is anybody's id. That is an access defect
        // of core's; it does not make that person a data subject here.
        $ids = [];
        foreach ($raw as $value) {
            if (!is_scalar($value) || is_bool($value) || !is_numeric($value)) {
                continue;
            }
            if ((float) $value != (int) $value) {
                // A stored "501.7" is not the id 501: core does not match it either.
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Which nodes of a module are ours, and which student its AI key names.
     *
     * @param \stdClass $cm The module row (id, course, idnumber, availability).
     * @param \stdClass|null $root The decoded tree, or null when it has none.
     * @param int[] $candidates Students to try against the key besides those the tree lists.
     * @return \stdClass {nodes: \stdClass[] of {node, actionid}, attribution: int[]|null, ambiguous: bool}
     */
    private static function claim(\stdClass $cm, ?\stdClass $root, array $candidates): \stdClass {
        $claim = (object) ['nodes' => [], 'attribution' => null, 'ambiguous' => false];

        $markedactions = [];
        foreach ($root === null ? [] : enableactivity_action::owned_user_nodes($root) as $node) {
            $actionid = enableactivity_action::action_id_of($node);
            $claim->nodes[] = (object) ['node' => $node, 'actionid' => $actionid];
            $markedactions[(int) $actionid] = true;
        }

        if (strpos((string) $cm->idnumber, aiactivity_key::PREFIX) !== 0) {
            return $claim;
        }
        $treeids = [];
        foreach ($root === null ? [] : self::user_nodes($root) as $node) {
            $treeids = array_merge($treeids, self::userids_of($node));
        }
        $claim->attribution = aiactivity_key::attribution(
            (string) $cm->idnumber,
            self::ai_actions_of_course((int) $cm->course),
            array_values(array_unique(array_merge($treeids, array_map('intval', $candidates))))
        );

        if ($claim->attribution === null || $root === null || isset($markedactions[$claim->attribution[0]])) {
            return $claim;
        }
        $generated = self::generated_nodes($root, $claim->attribution[1]);
        if (count($generated) === 1) {
            $claim->nodes[] = (object) ['node' => $generated[0], 'actionid' => $claim->attribution[0]];
        } else if (count($generated) > 1) {
            $claim->ambiguous = true;
        }

        return $claim;
    }

    /**
     * The modules whose ID number is the user's key for a createaiactivity action of their course.
     *
     * @param int $userid The user.
     * @return int[] Course module ids.
     */
    private static function modules_keyed_for(int $userid): array {
        global $DB;

        $sql = "SELECT a.id, r.courseid
                  FROM {local_coursedynamicrules_action} a
                  JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                 WHERE a.actiontype = :actiontype";
        $coursebykey = [];
        $rs = $DB->get_recordset_sql($sql, ['actiontype' => 'createaiactivity']);
        foreach ($rs as $action) {
            $coursebykey[aiactivity_key::for_action_user((int) $action->id, $userid)] = (int) $action->courseid;
        }
        $rs->close();

        $cmids = [];
        foreach (array_chunk(array_keys($coursebykey), 500) as $keys) {
            [$insql, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'key');
            $modules = $DB->get_records_select('course_modules', "idnumber $insql", $params, '', 'id, course, idnumber');
            foreach ($modules as $module) {
                // A key copied into another course - an import, a duplicate moved away - is not the
                // activity that action generated there.
                if ((int) $module->course === $coursebykey[$module->idnumber]) {
                    $cmids[] = (int) $module->id;
                }
            }
        }

        return $cmids;
    }

    /**
     * The createaiactivity actions of a course's rules.
     *
     * @param int $courseid The course.
     * @return int[] Action ids.
     */
    private static function ai_actions_of_course(int $courseid): array {
        global $DB;

        $sql = "SELECT a.id
                  FROM {local_coursedynamicrules_action} a
                  JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                 WHERE a.actiontype = :actiontype AND r.courseid = :courseid";

        return array_map('intval', $DB->get_fieldset_sql($sql, ['actiontype' => 'createaiactivity', 'courseid' => $courseid]));
    }

    /**
     * Every user node of a tree, at any depth, in tree order.
     *
     * @param object $root A decoded availability tree (the root, or any subtree).
     * @return \stdClass[]
     */
    private static function user_nodes(object $root): array {
        // Same walk as enableactivity_action::owned_user_nodes(): a node with a type is a leaf.
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

    /**
     * A course module row, or null when it is gone.
     *
     * @param int $cmid The course module id.
     * @return \stdClass|null The row with id, course, idnumber and availability.
     */
    private static function course_module(int $cmid): ?\stdClass {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, idnumber, availability');

        return $cm ?: null;
    }

    /**
     * Decode an availability tree, or null when there is nothing usable to decode.
     *
     * @param string|null $availability The stored JSON.
     * @return \stdClass|null The decoded root.
     */
    private static function decode(?string $availability): ?\stdClass {
        if (empty($availability)) {
            return null;
        }
        $root = json_decode($availability);

        return $root instanceof \stdClass ? $root : null;
    }
}
