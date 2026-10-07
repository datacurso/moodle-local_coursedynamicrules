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
use local_coursedynamicrules\local\owned_gate_eraser;

/**
 * Privacy provider for local_coursedynamicrules.
 *
 * The plugin's own three tables hold course configuration only - a rule, its conditions and its
 * actions - and no row in them names a person. Its personal data lives somewhere less obvious: the
 * enable-activity action grants a student access to an activity by writing that student's id into
 * {course_modules}.availability, a CORE column that no core component accounts for, and the
 * create-AI-activity action restricts the activity it generates to its student the same way, and
 * keys that activity's ID number with an HMAC of the action and the student (aiactivity_key).
 * core_availability's provider is a null_provider and so is availability_user's, so until this
 * class implemented the request interfaces those ids were declared by nobody, exported by nobody
 * and erased by nobody.
 *
 * WHAT THIS CLASS CLAIMS, AND WHY IT CLAIMS EXACTLY THAT
 *
 * A node this plugin wrote is a user-type restriction stamped with an identity-bearing marker
 * (enableactivity_action::marker_prefix()). A node a teacher wrote through "Restrict access" is
 * byte-for-byte the same thing WITHOUT that marker. So the rule this class follows, in both
 * directions, is: claim the marked nodes, claim nothing else.
 *
 * With one exception, and it rests on proof rather than on shape. An AI-generated activity carries
 * its key in the ID number, which saving the module settings keeps and which nobody can forge
 * without the site identifier. When the key verifies against one of the course's AI actions and one
 * student, the module was generated for that student, and the single unmarked user node listing
 * exactly that student - the shape the action writes - is the action's node even after its marker
 * was lost. owned_gate_eraser holds both rules, and the user_deleted observer uses it too, so an
 * ordinary account deletion and a data-subject request clean the same nodes.
 *
 * That rule is not conservatism for its own sake - it is what keeps the plugin from lying. Core
 * gives a provider no way to report a partial deletion: delete_data_for_user() returns nothing,
 * \core_privacy\manager discards what it is given, and tool_dataprivacy marks the request DELETED
 * and mails the data subject "your data was deleted" regardless (process_data_request_task.php:152,
 * :216). Throwing does not change that; it only emails the DPO. The contextlist this class returns
 * IS the attestation. So it must contain only contexts this class can actually clean - and it must
 * then clean every one of them.
 *
 * One case breaks that, and it is reported rather than hidden. When an AI key verifies but TWO
 * unmarked nodes list exactly the student, nothing says which one the action wrote. The module is in
 * the contextlist, because the key itself is the student's data; the erasure edits nothing on it and
 * throws once the other contexts are done, so the DPO is mailed the module ids for a manual cleanup.
 * The data subject is still told the request completed - that is tool_dataprivacy's behaviour, and
 * the exception is the only channel there is.
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
 * 1. Nodes written by createaiactivity_action before it began marking them, on an activity that
 *    carries no key either. That action records the module it creates in no params and no table, so
 *    such a node is indistinguishable from a teacher's restriction. Activities generated since carry
 *    the marker and the key; one that is marked but unkeyed receives its key the next time its rule
 *    fires for that student.
 * 2. A node of an enable-activity action whose marker somebody destroyed by saving the module's
 *    settings form - ANY save of it, not only one that touches restrictions. For an AI-generated
 *    activity the key covers this case, unless the same save also changed or cleared the ID number,
 *    which the form allows. Measured on the reference site on
 *    2026-09-17: changing an activity's name and saving destroyed the marker, because the form
 *    writes the JSON the browser's availability editor rebuilt from its own model rather than
 *    the stored tree (documented at enableactivity_action::MARKER_KEY). This is the reason the
 *    unmarked set grows with ordinary use rather than staying an edge case: 12 of the 19 user
 *    restrictions on that site carried no marker that day.
 * 3. A marker a RESTORE stripped from an enable-activity node. Core re-encodes a module's whole
 *    tree through each condition's save() whenever any sibling changed, and availability_user::save()
 *    emits only {type, userids}. When the course is restored with its rules, an AI-generated
 *    activity is re-marked afterwards from its key, as written rather than adopted, because the key
 *    verifies the restored action and the student (two candidate nodes leave it unmarked, and found
 *    through the key as above). On another site the key does not verify and the activity comes back
 *    unattributable. An enable-activity node is only re-adopted, as follows.
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
 * None of the three can be closed by a better provider: the adopted enable-activity node, in
 * particular, stays outside the export and the erasure, although an ordinary account deletion still
 * clears it through the user_deleted observer. All three close the same way: by owning a
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
 * AN ERASURE IS NOT A BAN, AND THE RULE KEEPS RUNNING
 *
 * This class removes an id. It does not tell the engine to stop granting, because a deletion request
 * is not an instruction to exclude somebody from a course they are still enrolled on. So if the
 * person remains enrolled, alive and still meeting a rule's condition, a later run of that rule
 * writes their id back - and that is new processing rather than an erasure that failed.
 *
 * Which path the deletion came through decides whether that can happen at all, and only one of them
 * leaves the door open:
 *
 * - A data-subject request, which is the ordinary case, ends in delete_user(). Every walk over a
 *   course's users goes through get_enrolled_sql(), and core restricts that to u.deleted = 0
 *   (lib/enrollib.php:1516). A deleted account is never evaluated again, so nothing can write the id
 *   back. This path closes itself.
 * - Context expiry under a retention policy splits. When a USER's own context expires,
 *   tool_dataprivacy deletes the data and then the account (expired_contexts_manager.php:535), which
 *   is the case above. When a COURSE or ACTIVITY context expires (:442, :468, :512), the accounts
 *   stay, and a student still enrolled and still meeting the condition is granted again on the next
 *   run - within fifteen minutes, since that is the tasks' schedule.
 *
 * Nothing here records that a student was granted once, deliberately: a rule that refused to act on
 * somebody because of something it did months ago would be a rule that stopped doing its job, and
 * the plugin has no basis for treating an expired retention window as a standing exclusion. What
 * matters is that nobody reads a course-context expiry as permanent while the person is still
 * enrolled and still qualifies. Stated here rather than worked around.
 *
 * An erasure that names the student an AI key belongs to also clears that key, on the module and on
 * its grade item while it still holds it, because the key is derived from the student's id. That
 * re-arms the action: after a course or activity context expiry, a student who is still enrolled and
 * still meets the condition has the activity generated again on the next run, which is a new call to
 * the AI service. After an account deletion nothing is generated, for the reason given above.
 *
 * Deleting an account WITHOUT an approved request runs only the user_deleted observer. That
 * observer walks each enable-activity action's recorded modules and then runs the same eraser this
 * class uses, so a generated activity is cleaned on that path too.
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
        // These are the KEYS of the bodies that reach the AI service, not a prose description of
        // them: a field named here that the service never receives misdescribes the transfer just as
        // badly as an omission. external_transfer_declaration_test.php compares both directions
        // against the bodies captured on the wire, for both requests that carry one - /activity/init
        // and the /activity/feedback plan approval. Keys left out are operational controls rather
        // than data about a person: with_images (configured per action, but it names nobody),
        // auto_approve (always true, since cron has nobody to approve a plan), service_id (the
        // calling plugin's billing identity), and on the approval thread_id (the id the service
        // issued), approval_status (always 'accept') and instruction (always empty). That is this
        // plugin's own classification, pinned in the same test; it is not a legal one.
        //
        // site_id, timezone and site_url are not set by this plugin at all: aiprovider_datacurso's
        // shared transport adds them to every POST body (datacurso_api_base::send_request()). They
        // are declared here anyway, because they leave the site in requests this plugin makes, and
        // their strings say who adds them. That plugin declares them as its own location as well.
        //
        // Course name and course URL are deliberately NOT separate entries. They reach the service
        // only inside `instructions` - the URL masked as a placeholder - so declaring them as fields
        // of their own would claim a channel that does not exist. Their travel is disclosed in the
        // `instructions` string instead, which is where it is literally true.
        //
        // In course_modules: the ids the enable-activity and AI actions write into availability, a
        // core column nobody else declares, named as a whole column because that is what it is: a
        // JSON availability tree, of which this plugin owns some nodes and other components own
        // others. And the key an AI-generated activity carries in its ID number (aiactivity_key), an
        // HMAC of the action and the student's id: it names nobody in clear, but it is derived from
        // the student's id, so it is exported and erased with it.
        $collection->add_database_table(
            'course_modules',
            [
                'availability' => 'privacy:metadata:course_modules:availability',
                'idnumber' => 'privacy:metadata:course_modules:idnumber',
            ],
            'privacy:metadata:course_modules'
        );

        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'instructions' => 'privacy:metadata:datacurso_ai:instructions',
                'lang' => 'privacy:metadata:datacurso_ai:lang',
                'site_id' => 'privacy:metadata:datacurso_ai:site_id',
                'site_url' => 'privacy:metadata:datacurso_ai:site_url',
                'timezone' => 'privacy:metadata:datacurso_ai:timezone',
                'userid' => 'privacy:metadata:datacurso_ai:userid',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }

    /**
     * The module contexts whose gate - a node this plugin owns - lists the user, or whose ID number
     * is the user's AI activity key.
     *
     * The search is owned_gate_eraser::modules_holding(), which the user_deleted observer shares: it
     * narrows on the marker and decides on the decoded tree, and finds AI activities through their
     * key so a lost marker does not hide them.
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
        $cmids = owned_gate_eraser::modules_holding($userid);
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
     * The users this plugin's own gates on a module list, and the student its AI key names.
     *
     * An HMAC key cannot be read back into a user id, so the ids the module's user nodes list are the
     * candidates, and the key decides which one it belongs to.
     *
     * @param userlist $userlist The userlist, carrying the context to inspect.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ((int) $context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $userids = owned_gate_eraser::users_in_module((int) $context->instanceid);
        if ($userids !== []) {
            $userlist->add_users($userids);
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
     * For an AI activity generated for the user, the key in its ID number is exported too, as
     * stored, because it is derived from the user's id (aiactivity_key).
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
            $holdings = owned_gate_eraser::holdings((int) $context->instanceid, $userid);
            if ($holdings === null) {
                continue;
            }

            // Keyed by action id so two same-named rules stay two, and an unresolvable marker does
            // not merge with another unresolvable one. A node with no readable action id keeps its
            // own slot under a negative key for the same reason.
            $rules = [];
            $holding = 0;
            $unresolved = 0;
            foreach ($holdings['actionids'] as $actionid) {
                $holding++;
                $key = $actionid ?? --$unresolved;
                $rules[$key] = self::rule_name_behind($actionid, $holdings['courseid']);
            }
            if ($holding === 0 && $holdings['aikey'] === null) {
                continue;
            }

            $data = (object) [
                'restrictions' => $holding,
                'rules' => array_values($rules),
                'whatthismeans' => get_string('privacy:export:idheld', 'local_coursedynamicrules', $holding),
            ];
            // The ID number of an AI activity generated for the user is derived from their id, so it
            // is theirs to see - exported as stored, with what it is.
            if ($holdings['aikey'] !== null) {
                $data->activitykey = $holdings['aikey'];
                $data->activitykeyexplained = get_string('privacy:export:activitykey', 'local_coursedynamicrules');
            }
            writer::with_context($context)->export_data($subcontext, $data);
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
        // invalidation it owes, so anything that leaves this loop early - the encode guard in the eraser,
        // or a database error on a later module - would carry the earlier modules' invalidations
        // away with it. The column would then say the id is gone while modinfo, which is what
        // students are actually evaluated against, still lets them in: the exact failure the
        // per-write invalidation existed to prevent, reintroduced by batching it.
        $courseids = [];
        $ambiguous = [];
        try {
            foreach ($contextlist->get_contexts() as $context) {
                $result = self::erase_in($context, [$userid]);
                if ($result['courseid'] !== null) {
                    $courseids[$result['courseid']] = $result['courseid'];
                }
                if ($result['ambiguous']) {
                    $ambiguous[] = (int) $context->instanceid;
                }
            }
        } finally {
            foreach ($courseids as $courseid) {
                rebuild_course_cache($courseid, true);
            }
        }
        self::report_ambiguous($ambiguous);
    }

    /**
     * Remove each listed user's id from this plugin's gates in the one approved module context.
     *
     * @param approved_userlist $userlist The approved users and their context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $result = self::erase_in($userlist->get_context(), array_map('intval', $userlist->get_userids()));
        if ($result['courseid'] !== null) {
            rebuild_course_cache($result['courseid'], true);
        }
        self::report_ambiguous($result['ambiguous'] ? [(int) $userlist->get_context()->instanceid] : []);
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
        $result = self::erase_in($context, null);
        if ($result['courseid'] !== null) {
            rebuild_course_cache($result['courseid'], true);
        }
        self::report_ambiguous($result['ambiguous'] ? [(int) $context->instanceid] : []);
    }

    /**
     * Erase the given users (or everyone) from this plugin's gates in a module context.
     *
     * Only CONTEXT_MODULE is acted on; see the class docblock. The erasure itself is
     * owned_gate_eraser::erase(), which the user_deleted observer shares.
     *
     * @param \context $context The context to erase.
     * @param int[]|null $userids The ids to remove, or null to remove every id.
     * @return array ['courseid' => int|null, 'ambiguous' => bool], as owned_gate_eraser::erase().
     */
    private static function erase_in(\context $context, ?array $userids): array {
        if ((int) $context->contextlevel !== CONTEXT_MODULE) {
            return ['courseid' => null, 'ambiguous' => false];
        }

        return owned_gate_eraser::erase((int) $context->instanceid, $userids);
    }

    /**
     * Report the AI activities an erasure left alone because the node to edit was ambiguous.
     *
     * The key proves the module was generated for the user, so the context is in the contextlist;
     * but two unmarked nodes list exactly that user and nothing says which one the action wrote.
     * Guessing could rewrite a teacher's restriction, so nothing on the module was edited. Raising is
     * the only channel core offers: the exception is caught per component and mailed to the data
     * protection officers (tool_dataprivacy\manager_observer). It is raised after every other context
     * was erased and its course invalidated, so one ambiguous module does not block the rest.
     *
     * @param int[] $cmids The modules left alone.
     * @return void
     * @throws \coding_exception When there is at least one.
     */
    private static function report_ambiguous(array $cmids): void {
        if ($cmids === []) {
            return;
        }
        throw new \coding_exception(
            'local_coursedynamicrules: ambiguous AI activity restriction left unedited, manual cleanup required',
            'course module(s) ' . implode(', ', $cmids) . ': more than one unmarked user restriction lists exactly '
                . 'the user the activity key names'
        );
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
     * The action id comes from the node's marker, read through the action class so the format stays
     * owned by the one class that writes it, or from the AI key that attributed an unmarked node.
     *
     * @param int|null $actionid The action that owns the node, or null when it is unreadable.
     * @param int $courseid The course the gated module belongs to.
     * @return string The rule name, or a stated substitute when no rule of this course owns the gate.
     */
    private static function rule_name_behind(?int $actionid, int $courseid): string {
        global $DB;

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
}
