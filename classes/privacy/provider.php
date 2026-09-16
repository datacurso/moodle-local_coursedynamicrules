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

namespace local_coursedynamicrules\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

/**
 * Privacy provider for local_coursedynamicrules.
 *
 * The plugin's own three tables hold course configuration only - a rule, its conditions and its
 * actions - and no row in them names a person. Its personal data lives somewhere less obvious: the
 * enable-activity action grants a student access to an activity by writing that student's id into
 * {course_modules}.availability, a CORE column that no core component accounts for.
 * core_availability's provider is a null_provider and so is availability_user's, so until this
 * class implemented the request interfaces those ids were declared by nobody, exported by nobody
 * and erased by nobody.
 *
 * WHAT THIS CLASS CLAIMS, AND WHY IT CLAIMS EXACTLY THAT
 *
 * A node this plugin wrote is a user-type restriction stamped with an identity-bearing marker
 * (enableactivity_action::marker_prefix()). A node a teacher wrote through "Restrict access" is
 * byte-for-byte the same thing WITHOUT that marker. There is no third signal. So the rule this
 * class follows, in both directions, is: claim the marked nodes, claim nothing else.
 *
 * That rule is not conservatism for its own sake - it is what keeps the plugin from lying. Core
 * gives a provider no way to report a partial deletion: delete_data_for_user() returns nothing,
 * \core_privacy\manager discards what it is given, and tool_dataprivacy marks the request DELETED
 * and mails the data subject "your data was deleted" regardless (process_data_request_task.php:152,
 * :216). Throwing does not change that; it only emails the DPO. The contextlist this class returns
 * IS the attestation. So it must contain only contexts this class can actually clean - and it must
 * then clean every one of them.
 *
 * The other direction is just as load-bearing. Claiming an unmarked node would mean erasing, inside
 * a request approved for THIS component, a restriction some teacher wrote for their own reasons -
 * and doing it in a way nobody can undo.
 *
 * ERASURE REMOVES THE ID AND KEEPS THE NODE
 *
 * The availability tree is AND-combined: a node that disappears stops restricting anything. Delete
 * the node and the activity a rule had opened for one student opens for the entire course. Removing
 * the id is what the request asks for; removing the node is the opposite of it. The rewrite edits
 * the decoded JSON in place rather than going through \core_availability\tree::save(), because
 * save() re-serialises every sibling node from its own condition class and would drop the very
 * marker this class depends on.
 *
 * ONLY CONTEXT_MODULE IS ACTED ON
 *
 * The action attaches a gate to an activity, never to a section, so a node of ours never reaches
 * course_sections.availability. Context expiry hands this class course and category contexts too;
 * ignoring them is correct rather than a gap, because the module contexts underneath are flagged
 * separately and processed child-first (tool_dataprivacy\expired_contexts_manager, ORDER BY
 * ctx.path DESC).
 *
 * WHAT REMAINS UNCLAIMABLE, STATED RATHER THAN HIDDEN
 *
 * Three kinds of id written by this plugin cannot be attributed to it, and this class does not
 * pretend otherwise:
 *
 * 1. Nodes written by createaiactivity_action before it began marking them. That action records the
 *    module it creates nowhere - not in params, not in a table - so a historical one is
 *    indistinguishable from a teacher's restriction. New ones carry the marker and are covered.
 * 2. Any node of ours whose marker a teacher destroyed by re-saving the module's restrictions
 *    through the core UI, which regenerates the tree from scratch (documented at
 *    enableactivity_action::MARKER_KEY).
 * 3. A marker a RESTORE stripped. Core re-encodes a module's whole tree through each condition's
 *    save() whenever any sibling changed, and availability_user::save() emits only {type, userids}.
 *    The restore plugin re-adopts what it can afterwards, but only for enable-activity actions,
 *    because it re-adopts by walking each action's recorded modules and createaiactivity records
 *    none. So an AI-generated activity that also carries a teacher's own restriction comes back from
 *    a restore unattributable.
 *
 * None of the three can be closed by a better provider. All three close the same way: by owning a
 * condition type of our own, so that ownership is structural instead of a property some other
 * component is free to drop. That is what the availability_coursedynamicrules plugin does. Until it
 * lands they are recorded here and in CHANGES.md, because a gap a reader can see is a different
 * thing from one the registry now hides behind a compliance tick.
 *
 * One asymmetry worth knowing, and it is not this class's to fix. An approved deletion request runs
 * this class and then deletes the account. Deleting an account WITHOUT such a request runs only the
 * user_deleted observer, and that observer works from each action's recorded modules - which the
 * create-AI-activity action does not keep. So a generated activity is cleaned by a data-subject
 * request and not by an ordinary account deletion.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data leaving Moodle for external processing.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        // These are the KEYS of the /activity/init payload built by
        // createaiactivity_action::execute(), not a prose description of it: a field named here
        // that the service never receives misdescribes the transfer just as badly as an omission,
        // and external_transfer_declaration_test.php compares both directions against the request
        // the action really sends. The three payload keys left out are operational controls of
        // the request rather than data about a person - with_images (configured per action, so it
        // varies between actions, but it names nobody), auto_approve (always true, since cron has
        // nobody to approve a plan) and service_id (the calling plugin's billing identity). That
        // is this plugin's own classification, pinned in the same test; it is not a legal one.
        //
        // Course name and course URL are deliberately NOT separate entries. They reach the service
        // only inside `instructions`, and only when the teacher writes {$a->coursename} or
        // {$a->courseurl} in the prompt (build_prompt() substitutes them), so declaring them as
        // fields of their own would claim a channel that does not exist. Their travel is disclosed
        // in the `instructions` string instead, which is where it is literally true.
        // The ids the enable-activity action writes into a core column nobody else declares. It is
        // named as a whole column rather than as a field list because that is what the column is: a
        // JSON availability tree, of which this plugin owns some nodes and other components own
        // others.
        $collection->add_database_table(
            'course_modules',
            ['availability' => 'privacy:metadata:course_modules:availability'],
            'privacy:metadata:course_modules'
        );

        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'instructions' => 'privacy:metadata:datacurso_ai:instructions',
                'lang' => 'privacy:metadata:datacurso_ai:lang',
                'site_url' => 'privacy:metadata:datacurso_ai:site_url',
                'userid' => 'privacy:metadata:datacurso_ai:userid',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }

    /**
     * The module contexts whose gate - a node this plugin owns - lists the user.
     *
     * The ids live inside a JSON tree, so they cannot be matched in SQL: a LIKE on "24" matches
     * "124" and "240" just as happily. The query narrows to the rows that could carry one of our
     * markers at all, and ownership and membership are both decided in PHP on the decoded tree,
     * where an integer is an integer and a marker is a marker.
     *
     * Streamed with a recordset rather than loaded with get_records_select(): the LIKE has a leading
     * wildcard on a TEXT column, so it cannot use an index and the candidate set is bounded only by
     * how many modules the site has. There is no reason to hold all their availability trees in
     * memory at once.
     *
     * Never throws and always returns a contextlist, including for a user who has already been
     * deleted and has no context of their own - core asserts exactly that
     * (privacy/tests/privacy/provider_advanced_test.php, test_component_understands_deleted_users).
     *
     * @param int $userid The user to search for.
     * @return contextlist The module contexts, possibly empty.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $cmids = self::modules_gating($userid);
        if ($cmids === []) {
            return $contextlist;
        }

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $params['contextlevel'] = CONTEXT_MODULE;
        $contextlist->add_from_sql(
            "SELECT id FROM {context} WHERE contextlevel = :contextlevel AND instanceid $insql",
            $params
        );

        return $contextlist;
    }

    /**
     * The users this plugin's own gates on a module list.
     *
     * @param userlist $userlist The userlist, carrying the context to inspect.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ((int) $context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = self::course_module((int) $context->instanceid);
        $root = $cm ? self::decode($cm->availability) : null;
        if ($root === null) {
            return;
        }

        $userids = [];
        foreach (enableactivity_action::owned_user_nodes($root) as $node) {
            $userids = array_merge($userids, self::userids_of($node));
        }
        if ($userids !== []) {
            $userlist->add_users(array_values(array_unique($userids)));
        }
    }

    /**
     * Export, per gated activity, that a rule of this plugin opened it for the user and which one.
     *
     * The rule's name is resolved through the marker's action id when that action still exists. When
     * it does not - a marker can outlive its action, which the action class documents - the export
     * still states the grant and says the owning rule is gone, because the access itself is the fact
     * the data subject is entitled to, not the bookkeeping behind it.
     *
     * @param approved_contextlist $contextlist The approved contexts to export from.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        $subcontext = [get_string('privacy:export:activityaccess', 'local_coursedynamicrules')];

        foreach ($contextlist->get_contexts() as $context) {
            if ((int) $context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $cm = self::course_module((int) $context->instanceid);
            $root = $cm ? self::decode($cm->availability) : null;
            if ($root === null) {
                continue;
            }

            $rules = [];
            foreach (enableactivity_action::owned_user_nodes($root) as $node) {
                if (in_array($userid, self::userids_of($node), true)) {
                    $rules[] = self::rule_name_behind($node);
                }
            }
            if ($rules === []) {
                continue;
            }

            // An activity two rules manage carries two gates: name every rule that granted access.
            writer::with_context($context)->export_data($subcontext, (object) [
                'granted' => true,
                'rules' => array_values(array_unique($rules)),
            ]);
        }
    }

    /**
     * Remove the user's id from this plugin's gates in every approved module context.
     *
     * @param approved_contextlist $contextlist The approved contexts to erase from.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::remove_from_module($context, [$userid]);
        }
    }

    /**
     * Remove each listed user's id from this plugin's gates in the one approved module context.
     *
     * @param approved_userlist $userlist The approved users and their context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        self::remove_from_module($userlist->get_context(), array_map('intval', $userlist->get_userids()));
    }

    /**
     * Empty this plugin's gates in the module context. The nodes stay, so the activity stays closed.
     *
     * @param \context $context The context to erase.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        self::remove_from_module($context, null);
    }

    /**
     * Rewrite a module's own gates without the given users (or without anyone), keeping every node.
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
     * The course cache is rebuilt when, and only when, something changed. The tree students are
     * evaluated against comes from modinfo, so a write nobody invalidates erases the id in the
     * database and leaves the student's access exactly as it was until some unrelated edit happens
     * to rebuild it.
     *
     * @param \context $context The module context.
     * @param int[]|null $userids The ids to remove, or null to remove every id.
     * @return void
     */
    private static function remove_from_module(\context $context, ?array $userids): void {
        global $DB;

        if ((int) $context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = self::course_module((int) $context->instanceid);
        $root = $cm ? self::decode($cm->availability) : null;
        if ($root === null) {
            return;
        }

        $changed = false;
        foreach (enableactivity_action::owned_user_nodes($root) as $node) {
            $before = self::userids_of($node);
            $after = $userids === null
                ? []
                : array_values(array_filter($before, static function (int $id) use ($userids): bool {
                    return !in_array($id, $userids, true);
                }));
            if ($after !== $before) {
                $node->userids = $after;
                $changed = true;
            }
        }

        if ($changed) {
            $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cm->id]);
            rebuild_course_cache((int) $cm->course, true);
        }
    }

    /**
     * The modules whose own gates list the user.
     *
     * @param int $userid The user.
     * @return int[] Course module ids, possibly empty.
     */
    private static function modules_gating(int $userid): array {
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
                    $cmids[] = (int) $cm->id;
                    break;
                }
            }
        }
        $rs->close();

        return $cmids;
    }

    /**
     * The name of the rule whose action owns a gate, for the export.
     *
     * @param \stdClass $node A node this plugin owns.
     * @return string The rule name, or a stated substitute when the owning action is gone.
     */
    private static function rule_name_behind(\stdClass $node): string {
        global $DB;

        $marker = (string) ($node->source ?? '');
        $actionid = (int) substr($marker, strlen(enableactivity_action::marker_prefix()));
        if ($actionid > 0) {
            $name = $DB->get_field_sql(
                "SELECT r.name
                   FROM {local_coursedynamicrules_action} a
                   JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                  WHERE a.id = :actionid",
                ['actionid' => $actionid]
            );
            if ($name !== false && $name !== null && $name !== '') {
                return (string) $name;
            }
        }

        return get_string('privacy:export:ruledeleted', 'local_coursedynamicrules');
    }

    /**
     * A course module row, or null when it is gone.
     *
     * @param int $cmid The course module id.
     * @return \stdClass|null The row with id, course and availability.
     */
    private static function course_module(int $cmid): ?\stdClass {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, availability');

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

    /**
     * The user ids a node lists, as integers: the stored list can mix strings and integers.
     *
     * A node whose list is not a list is corrupt. It is read as empty and left exactly as it is,
     * rather than throwing: an erasure request must not die half-way through on one bad row.
     *
     * @param \stdClass $node A node this plugin owns.
     * @return int[]
     */
    private static function userids_of(\stdClass $node): array {
        $userids = $node->userids ?? null;
        // json_decode() hands back a stdClass, not an array, whenever the stored list has gaps or
        // string keys - which is exactly what a gapped PHP array encodes to. The ids are still there
        // and they are still personal data, so they are recovered rather than dropped. Dropping them
        // would leave the module out of the contextlist, and the contextlist is the attestation:
        // tool_dataprivacy would tell the student their data was erased while these ids stayed.
        if (is_object($userids)) {
            $userids = (array) $userids;
        }
        if (!is_array($userids)) {
            return [];
        }

        return array_values(array_map('intval', $userids));
    }
}
