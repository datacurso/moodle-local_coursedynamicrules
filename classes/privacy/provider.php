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
 * 2. Any node of ours whose marker somebody destroyed by saving the module's settings form -
 *    ANY save of it, not only one that touches restrictions. Measured on the reference site on
 *    2026-09-17: changing an activity's name and saving destroyed the marker, because the form
 *    writes the JSON the browser's availability editor rebuilt from its own model rather than
 *    the stored tree (documented at enableactivity_action::MARKER_KEY). This is the reason the
 *    unmarked set grows with ordinary use rather than staying an edge case: 12 of the 19 user
 *    restrictions on that site carried no marker that day.
 * 3. A marker a RESTORE stripped. Core re-encodes a module's whole tree through each condition's
 *    save() whenever any sibling changed, and availability_user::save() emits only {type, userids}.
 *    The restore plugin re-adopts what it can afterwards, but only for enable-activity actions,
 *    because it re-adopts by walking each action's recorded modules and createaiactivity records
 *    none. So an AI-generated activity that also carries a teacher's own restriction comes back from
 *    a restore unattributable.
 *
 *    A re-adopted marker is not attributable either, and this class refuses it. The re-adoption
 *    claims the tree's single unmarked user node, which on a module the action still lists but whose
 *    own gate a teacher has since replaced is the TEACHER'S restriction: measured on this tree, a
 *    teacher's list of two students came back with one after an erasure approved for this component.
 *    The engine still needs that marker - apply_availability() matches on it alone, so an unmarked
 *    gate reads as no gate and a second, empty one gets appended, hiding the activity from everybody
 *    - so the re-adoption writes it and additionally records that it was deduced
 *    (enableactivity_action::MARKER_ADOPTED_KEY), and owned_user_nodes() skips anything carrying it.
 *    The engine may act on a deduction; nothing that attests to a regulator may. The cost is stated
 *    rather than hidden: a gate this plugin really did write, whose marker a restore stripped, is no
 *    longer exported or erased. Nodes re-adopted by releases 1.8.4 and 1.8.5, which recorded no such
 *    flag, are indistinguishable from written ones and remain claimable.
 *
 * None of the three can be closed by a better provider. All three close the same way: by owning a
 * condition type of our own, so that ownership is structural instead of a property some other
 * component is free to drop. That is what the availability_coursedynamicrules plugin does. Until it
 * lands they are recorded here and in CHANGES.md, because a gap a reader can see is a different
 * thing from one the registry now hides behind a compliance tick.
 *
 * WHAT AN ERASURE DOES TO ACCESS, WHICH IS NOT ALWAYS WHAT A READER EXPECTS
 *
 * Removing an id from a restriction changes what that restriction says, and that is the point. What
 * it does to the person's ACCESS is a separate question, and the answer splits cleanly in two.
 *
 * In the arrangements THIS PLUGIN writes - its restriction a direct child of an AND root, which is
 * all apply_availability() ever produces - an erasure either takes access away or changes nothing at
 * all, and never grants it. Measured: half and half. The half that changes nothing is a teacher's
 * own restriction, a date not yet arrived or a grade not reached, which was closing the activity
 * before and still is.
 *
 * Once somebody has rearranged the activity's restrictions around ours, the answer inverts. Measured
 * on a restriction of ours nested inside a negating group: it never takes access away, and half the
 * time it GRANTS it. That needs saying plainly, because it surprises people and because no design
 * avoids it.
 * The rearrangement is a negating group - "must NOT match" - and this plugin never puts one there:
 * it gets there when somebody regroups the activity's restrictions around ours, or when a restore's
 * re-adoption stamps a node that was already inside one. Under a negation the list means the
 * opposite of what it means anywhere else: it names the people kept OUT. So erasing somebody from it
 * admits them to an activity a teacher had excluded them from, and emptying it entirely - which is
 * what erasing a whole context does - admits everyone it named.
 *
 * That cannot be designed away, and refusing to act on such a node was tried and withdrawn. THERE,
 * who is excluded IS the personal data: an erasure that declines to remove it is an erasure that did
 * not happen, reported to the person as one that did. The one reassurance that does hold, and it was
 * measured too: somebody who is not named in the restriction is never affected either way.
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
     * Always returns a contextlist for a user who has already been deleted and has no context of
     * their own, rather than failing on the way to one - core asserts exactly that
     * (privacy/tests/privacy/provider_advanced_test.php, test_component_understands_deleted_users).
     * It reads no user record and resolves no user context, so nothing here depends on the account
     * still existing. A database failure still raises, as everywhere else.
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
     * Export, per activity, WHAT IS STORED about the user - and nothing derived from it.
     *
     * This method used to report `granted => true`, under a heading that read "Activity access
     * granted". It was false, and not only in exotic trees. Measured against core's own evaluation:
     * an ordinary AND tree holding our restriction beside a date condition that has not arrived yet
     * reports "granted" for a student who cannot open the activity - which is the commonest shape
     * there is, since a teacher restricting an activity by date and a rule opening it for one
     * student are the two things this plugin exists to combine.
     *
     * The mistake was not the arithmetic, it was taking the job at all. Whether an activity is open
     * to somebody is a property of the WHOLE tree evaluated for that person at a moment in time, and
     * of things outside the tree entirely - the module's visibility, the person's capabilities, their
     * groups. A privacy export discloses data held, not outcomes computed, and core's own providers
     * do exactly that: core_group exports a group name and the time it was joined, mod_choice the
     * answer and when it changed. Neither derives anything.
     *
     * So three statements, each true for every tree that can be stored:
     *
     * 1. The user's id is in N restrictions on this activity that this plugin manages.
     * 2. These rules are associated with those restrictions NOW - which is deliberately not a claim
     *    that they put the id there. The marker proves this plugin wrote the NODE, and not even
     *    that reliably: the restore's re-adoption can stamp a node a teacher wrote. Any sentence
     *    resting on authorship is falsifiable until ownership is structural.
     * 3. This export does not say whether the activity is open to the user.
     *
     * The rules are counted and de-duplicated by ACTION id rather than by name. Two rules of one
     * course can share a name - a course copy makes that ordinary - and de-duplicating on the name
     * collapsed two distinct restrictions into one line, leaving the reader unable to tell one from
     * four.
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

            // Keyed by action id so two same-named rules stay two, and an unresolvable marker does
            // not merge with another unresolvable one. A node with no readable action id keeps its
            // own slot under a negative key for the same reason.
            $rules = [];
            $holding = 0;
            $unresolved = 0;
            foreach (enableactivity_action::owned_user_nodes($root) as $node) {
                if (!in_array($userid, self::userids_of($node), true)) {
                    continue;
                }
                $holding++;
                $actionid = enableactivity_action::action_id_of($node);
                $key = $actionid ?? --$unresolved;
                $rules[$key] = self::rule_name_behind($node, (int) $cm->course);
            }
            if ($holding === 0) {
                continue;
            }

            writer::with_context($context)->export_data($subcontext, (object) [
                'restrictions' => $holding,
                'rules' => array_values($rules),
                'whatthismeans' => get_string('privacy:export:idheld', 'local_coursedynamicrules', $holding),
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

        // One invalidation per COURSE that changed, however many of its activities did. Each one
        // bumps the course's cache revision and throws away the cached settings of the whole course,
        // so doing it per activity makes a student granted a dozen activities pay a dozen full
        // invalidations inside one request - and every other user of that course rebuild from
        // scratch as many times. The plugin's user-deletion observer already works this way.
        //
        // The finally is load-bearing, not tidiness. Batching separates a write from the
        // invalidation it owes, so anything that leaves this loop early - the encode guard below,
        // or a database error on a later module - would carry the earlier modules' invalidations
        // away with it. The column would then say the id is gone while modinfo, which is what
        // students are actually evaluated against, still lets them in: the exact failure the
        // per-write invalidation existed to prevent, reintroduced by batching it.
        $courseids = [];
        try {
            foreach ($contextlist->get_contexts() as $context) {
                $courseid = self::remove_from_module($context, [$userid]);
                if ($courseid !== null) {
                    $courseids[$courseid] = $courseid;
                }
            }
        } finally {
            foreach ($courseids as $courseid) {
                rebuild_course_cache($courseid, true);
            }
        }
    }

    /**
     * Remove each listed user's id from this plugin's gates in the one approved module context.
     *
     * @param approved_userlist $userlist The approved users and their context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $courseid = self::remove_from_module($userlist->get_context(), array_map('intval', $userlist->get_userids()));
        if ($courseid !== null) {
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * Empty this plugin's gates in the module context, keeping the nodes themselves.
     *
     * The nodes are kept because deleting one removes a restriction, and an activity with one fewer
     * restriction is open to more people. Keeping it is NOT the same as the activity staying closed,
     * which an earlier version of this line claimed: where the node sits under a negating group it
     * lists the people kept OUT, so emptying it admits exactly them. Measured, not reasoned. That
     * consequence cannot be designed away - there, who is excluded IS the personal data, and an
     * erasure that refuses to remove it is an erasure that did not happen.
     *
     * @param \context $context The context to erase.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $courseid = self::remove_from_module($context, null);
        if ($courseid !== null) {
            rebuild_course_cache($courseid, true);
        }
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
     * Returns the course that must be invalidated rather than invalidating it, so a caller holding
     * several contexts of one course can do it once. The tree students are evaluated against comes
     * from modinfo, so a write nobody invalidates erases the id in the database and leaves the
     * student's access exactly as it was until some unrelated edit happens to rebuild it - the
     * caller owes that invalidation, it is not optional.
     *
     * @param \context $context The module context.
     * @param int[]|null $userids The ids to remove, or null to remove every id.
     * @return int|null The course whose cache the caller must invalidate, or null when nothing changed.
     */
    private static function remove_from_module(\context $context, ?array $userids): ?int {
        global $DB;

        if ((int) $context->contextlevel !== CONTEXT_MODULE) {
            return null;
        }
        $cm = self::course_module((int) $context->instanceid);
        $root = $cm ? self::decode($cm->availability) : null;
        if ($root === null) {
            return null;
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

        if (!$changed) {
            return null;
        }

        // Refusing to write beats writing false. json_encode() returns false rather than throwing,
        // and an empty availability column means NO restrictions at all - the activity would open to
        // the whole course, which is the single outcome this class exists to prevent. The input came
        // from json_decode() of a stored tree, so this is not expected; it is guarded because the
        // cost of being wrong is the harm the design is built around. Raising is the only channel
        // core offers: the exception is caught per component and mailed to the data protection
        // officers with this method's name (tool_dataprivacy\manager_observer), which is a person
        // learning about it instead of a column being blanked in silence.
        $encoded = json_encode($root);
        if (!is_string($encoded)) {
            throw new \coding_exception(
                'local_coursedynamicrules: refusing to write an unencodable availability tree',
                'course module ' . $cm->id . ': ' . json_last_error_msg()
            );
        }

        $DB->set_field('course_modules', 'availability', $encoded, ['id' => $cm->id]);

        return (int) $cm->course;
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
     * Scoped to the gate's OWN course, which is load-bearing rather than defensive. A course import
     * brings activities without rules - the plugin documents that as intended - so an imported module
     * can arrive carrying a marker minted wherever it was exported from. Every action id on the site
     * comes from one table and one sequence, so that id very probably belongs to a live action of a
     * different rule in a different course. Resolving it by id alone would answer "which rule opened
     * this for you?" with the name of a rule that never did, inside the one document where an
     * invented answer is worse than no answer.
     *
     * The marker is read through the action class rather than by reaching for the property, so the
     * format stays owned by the one class that writes it.
     *
     * @param \stdClass $node A node this plugin owns.
     * @param int $courseid The course the gated module belongs to.
     * @return string The rule name, or a stated substitute when no rule of this course owns the gate.
     */
    private static function rule_name_behind(\stdClass $node, int $courseid): string {
        global $DB;

        $actionid = enableactivity_action::action_id_of($node);
        if ($actionid !== null) {
            $name = $DB->get_field_sql(
                "SELECT r.name
                   FROM {local_coursedynamicrules_action} a
                   JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                  WHERE a.id = :actionid AND r.courseid = :courseid",
                ['actionid' => $actionid, 'courseid' => $courseid]
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
        $ids = is_array($userids) ? array_values(array_map('intval', $userids)) : [];

        // And the SINGULAR key, which availability_user has always honoured and still does: its
        // constructor pushes $structure->userid onto the list it evaluates
        // (availability/condition/user/classes/condition.php). A node carrying it restricts the
        // activity to that student exactly as a list would, and it can carry this plugin's marker,
        // because a node is stamped by its TYPE and nothing about the stamping inspects the shape
        // inside it. Reading only the plural key would therefore leave a student this plugin gated
        // outside both the export and the erasure, while the deletion request reported success.
        if (isset($node->userid)) {
            $ids[] = (int) $node->userid;
        }

        return $ids;
    }
}
