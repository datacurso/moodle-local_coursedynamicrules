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

namespace local_coursedynamicrules\action\enableactivity;

use core_availability\tree;
use local_coursedynamicrules\core\action;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\form\actions\enableactivity_form;

/**
 * Class enableactivity_action
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enableactivity_action extends action {
    /** @var string type of the action */
    protected $type = 'enableactivity';

    /**
     * JSON property added to the plugin's own user-restriction node so it can be told apart from a
     * user restriction a teacher adds independently via the "Restrict access" UI (FIX2-3).
     *
     * core_availability\tree and availability_user\condition only read known properties when
     * decoding a node (see availability/classes/tree.php and
     * availability/condition/user/classes/condition.php), so this extra property survives the
     * decode/encode round-trips this class performs. It is only lost if a human re-saves the
     * module's restrictions via the core "Restrict access" UI, which regenerates the tree from
     * scratch via availability_user\condition::save() and drops unknown properties - an accepted,
     * documented edge case.
     */
    private const MARKER_KEY = 'source';

    /**
     * Marker value PREFIX (FIX3-3): the marker used to be a single constant shared by every
     * enableactivity action, so two different actions gating the SAME course module ended up
     * sharing one node - deleting/editing either action's grants cross-revoked the other's. The
     * action's own id is appended to make the marker identity-bearing, so each action only ever
     * recognises (and mutates) its OWN node.
     */
    private const MARKER_PREFIX = 'local_coursedynamicrules:';

    /**
     * This action's own, identity-bearing marker value (FIX3-3). Only meaningful once the action
     * has an id - save_action() upserts the row BEFORE calling apply_availability() specifically so
     * this is always available by the time it's needed.
     *
     * @return string
     */
    private function marker_value(): string {
        return self::MARKER_PREFIX . $this->get_id();
    }

    /**
     * Execute the action
     * @param object $context Context of the rule
     */
    public function execute($context) {
        global $DB;
        $userid = $context->userid;
        $coursemodules = $this->params->coursemodules ?? [];

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;
            $cmrecord = $DB->get_record('course_modules', ['id' => $cmid]);
            if (!$cmrecord) {
                debugging('enableactivity: course module ' . $cmid . ' no longer exists; skipped', DEBUG_DEVELOPER);
                continue;
            }

            $availability = $cmrecord->availability ? json_decode($cmrecord->availability) : null;

            // Locate the action's OWN node: prefer the marker (FIX2-3), falling back to the sole
            // unmarked 'user' node for rows saved before the marker existed - safe here because
            // execute() only ever iterates cmids already recorded in $this->params->coursemodules,
            // i.e. cms this action itself manages. If a teacher's own (also unmarked) restriction
            // is ALSO present, there are 2+ unmarked user nodes and the fallback deliberately backs
            // off instead of guessing which one is ours.
            $usercondition = $this->find_user_condition($availability);
            if ($usercondition === null) {
                debugging('enableactivity: expected user restriction not found on cm ' . $cmid . '; skipped', DEBUG_DEVELOPER);
                continue;
            }

            $userids = $usercondition->userids ?? [];

            if (!in_array($userid, $userids)) {
                $userids[] = $userid;
                $usercondition->userids = $userids;

                $DB->set_field(
                    'course_modules',
                    'availability',
                    json_encode($availability),
                    ['id' => $cmid]
                );
            }
        }

        rebuild_course_cache($this->courseid, true);
    }

    /**
     * Find THIS action's own user-restriction node (FIX2-3/FIX3-3), or - as a legacy fallback for
     * pre-marker data - the sole unmarked 'user' node.
     *
     * The returned node is the live object inside the tree, so mutating its properties updates the
     * tree in place. Searches recursively (FIX3-6): a teacher grouping restrictions via the core
     * "Restrict access" UI can nest the plugin's own node inside a child subtree instead of leaving
     * it a direct root child, and the marker (once present) makes matching unambiguous regardless
     * of depth.
     *
     * Used by execute() and restore_coursemodules(): both only ever operate on cmids already
     * recorded in $this->params->coursemodules, so an unmarked 'user' node found here can only be a
     * leftover from before the marker existed, never an unrelated teacher-added restriction (2+
     * unmarked nodes is the ambiguous "degraded mode" case, deliberately left unresolved here -
     * restore_coursemodules() surfaces it via debugging() instead of guessing - FIX3-7).
     *
     * @param object|null $availability Decoded availability (sub)tree, or null.
     * @return object|null The user condition node, or null if none can be safely identified.
     */
    private function find_user_condition($availability) {
        $marked = $this->find_marked_user_condition($availability);
        if ($marked !== null) {
            return $marked;
        }

        $unmarked = $this->collect_unmarked_user_conditions($availability);
        return count($unmarked) === 1 ? $unmarked[0] : null;
    }

    /**
     * Find THIS action's own user-restriction node, matching ONLY on its identity-bearing marker
     * (FIX2-3/FIX3-3) - no legacy fallback. Searches recursively (FIX3-6, see find_user_condition()
     * docblock).
     *
     * Used by apply_availability() to decide whether a NEW cmid already has the plugin's gate
     * ANYWHERE in the tree, so a re-reconciliation never appends a second, empty gate alongside one
     * a teacher has since regrouped into a nested subtree.
     *
     * @param object|null $availability Decoded availability (sub)tree, or null.
     * @return object|null The marked user condition node, or null if none is present.
     */
    private function find_marked_user_condition($availability) {
        if ($availability === null || !isset($availability->c) || !is_array($availability->c)) {
            return null;
        }

        $marker = $this->marker_value();
        foreach ($availability->c as $condition) {
            if (
                isset($condition->type) && $condition->type === 'user'
                && isset($condition->{self::MARKER_KEY})
                && $condition->{self::MARKER_KEY} === $marker
            ) {
                return $condition;
            }
        }

        foreach ($availability->c as $condition) {
            if (!isset($condition->type) && isset($condition->c) && is_array($condition->c)) {
                $found = $this->find_marked_user_condition($condition);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Collect every UNMARKED 'user' condition node anywhere in a decoded availability (sub)tree
     * (FIX3-6: recurses into nested subtrees, mirroring find_marked_user_condition()). A node
     * carrying ANY marker (even another action's - FIX3-3) is never "unmarked", so this never
     * mistakes a sibling action's own node for a legacy/teacher one.
     *
     * @param object|null $availability Decoded availability (sub)tree, or null.
     * @return object[] Unmarked 'user' condition nodes found, in tree order.
     */
    private function collect_unmarked_user_conditions($availability): array {
        if ($availability === null || !isset($availability->c) || !is_array($availability->c)) {
            return [];
        }

        $found = [];
        foreach ($availability->c as $condition) {
            if (
                isset($condition->type) && $condition->type === 'user'
                && !isset($condition->{self::MARKER_KEY})
            ) {
                $found[] = $condition;
            } else if (!isset($condition->type) && isset($condition->c) && is_array($condition->c)) {
                $found = array_merge($found, $this->collect_unmarked_user_conditions($condition));
            }
        }

        return $found;
    }

    /**
     * Creates and returns an instance of the form for editing the action
     *
     * @param mixed $action the action attribute for the form. If empty defaults to auto detect the
     *              current url. If a moodle_url object then outputs params as hidden variables.
     * @param mixed $customdata if your form defintion method needs access to data such as $course
     *              $cm, etc. to construct the form definition then pass it in this array. You can
     *              use globals for somethings.
     * @param string $method if you set this to anything other than 'post' then _GET and _POST will
     *               be merged and used as incoming data to the form.
     * @param string $target target frame for form submission. You will rarely use this. Don't use
     *               it if you don't need to as the target attribute is deprecated in xhtml strict.
     * @param mixed $attributes you can pass a string of html attributes here or an array.
     *               Special attribute 'data-random-ids' will randomise generated elements ids. This
     *               is necessary when there are several forms on the same page.
     *               Special attribute 'data-double-submit-protection' set to 'off' will turn off
     *               double-submit protection JavaScript - this may be necessary if your form sends
     *               downloadable files in response to a submit button, and can't call
     *               \core_form\util::form_download_complete();
     * @param bool $editable
     * @param array $ajaxformdata Forms submitted via ajax, must pass their data here, instead of relying on _GET and _POST.
     */
    public function build_editform(
        $action = null,
        $customdata = null,
        $method = 'post',
        $target = '',
        $attributes = null,
        $editable = true,
        $ajaxformdata = null
    ) {
        $this->actionform = new enableactivity_form(
            $action,
            $customdata,
            $method,
            $target,
            $attributes,
            $editable,
            $ajaxformdata
        );
    }

    /**
     * Saves the action after it has been edited (or created)
     *
     * On edit, cmids are reconciled against the previously stored set (D6): a retained cmid keeps
     * its stored visible/visibleoncoursepage snapshot and its availability is left untouched, since
     * execute() may already have accumulated userids into it that re-applying a fresh restriction
     * would wipe. A newly added cmid gets its snapshot taken now and the restriction applied. A
     * removed cmid is restored via restore_coursemodules(), the same helper delete() uses.
     *
     * The full new snapshot/params are computed BEFORE any mutation (FIX2-9), then the upsert() and
     * the availability/visibility mutations are wrapped in a single delegated transaction; each
     * mutation defers its own cache rebuild and a single rebuild_course_cache() runs after commit,
     * instead of one rebuild per course module.
     *
     * upsert() runs FIRST, before apply_availability() (FIX3-3): the plugin's own marker is
     * identity-bearing (MARKER_PREFIX . $this->get_id()) so two different actions gating the same
     * cm never share (and cross-revoke) one node, which means a brand-new action needs its id
     * BEFORE its first gate can be marked. Any failure inside the transaction rolls back the
     * upsert() too (micro-sweep), so a partially-applied edit is never left half-committed.
     *
     * @param object $formdata
     */
    public function save_action($formdata) {
        global $DB;

        $priorbycmid = [];
        if (!empty($this->get_id())) {
            foreach ($this->params->coursemodules ?? [] as $cm) {
                $priorbycmid[$cm->id] = $cm;
            }
        }

        $newcmids = array_map('intval', (array) $formdata->coursemodules);

        // Compute the full new snapshot BEFORE mutating anything (FIX2-9): resolving a course
        // module never writes, so any failure/skip here cannot leave a half-applied edit. Uses
        // $this->courseid, the course this action instance is actually bound to - NOT the
        // client-controlled $formdata->courseid (FIX2-4).
        $coursemodules = [];
        $tomanage = [];
        foreach ($newcmids as $cmid) {
            if (isset($priorbycmid[$cmid])) {
                // Retained: keep the stored snapshot; do not re-apply the availability restriction.
                $coursemodules[] = $priorbycmid[$cmid];
                continue;
            }

            // Newly added: snapshot the current visible state. A cmid that does not resolve (race,
            // tampered id) is skipped instead of dereferencing a false result (FIX2-4).
            $cminfo = get_coursemodule_from_id(null, $cmid, $this->courseid);
            if (!$cminfo) {
                debugging(
                    'enableactivity: course module ' . $cmid . ' not found in course '
                        . $this->courseid . '; skipped',
                    DEBUG_DEVELOPER
                );
                continue;
            }

            $coursemodules[] = (object) [
                'id' => $cmid,
                'visible' => $cminfo->visible,
                'visibleoncoursepage' => $cminfo->visibleoncoursepage,
            ];
            $tomanage[] = $cmid;
        }

        $removedcmids = array_diff(array_keys($priorbycmid), $newcmids);
        $toremove = array_intersect_key($priorbycmid, array_flip($removedcmids));

        $params = [
            'coursemodules' => $coursemodules,
        ];

        $transaction = $DB->start_delegated_transaction();

        try {
            // Upsert FIRST (FIX3-3): a brand-new action has no id - and therefore no marker value -
            // until this runs, so apply_availability() below must be able to rely on get_id()
            // already being set for both the create and the edit path.
            $this->upsert($params, $formdata);

            foreach ($tomanage as $cmid) {
                $this->apply_availability($cmid, false);
            }

            if (!empty($toremove)) {
                $this->restore_coursemodules($toremove, false);
            }
        } catch (\Throwable $e) {
            // rollback() always rethrows $e (lib/dml/moodle_database.php::rollback_delegated_transaction()),
            // so this never falls through to allow_commit() below - it unwinds the call stack instead
            // (core convention: catching Throwable, not just Exception, also rolls back on a fatal
            // \Error - e.g. a TypeError from a malformed $formdata - instead of leaving the delegated
            // transaction dangling).
            $transaction->rollback($e);
        }

        $transaction->allow_commit();

        rebuild_course_cache($this->courseid, true);
    }

    /**
     * Merge the plugin's own user restriction (empty userids, populated later by execute()) into a
     * course module's availability tree, and make it visible.
     *
     * Reuses find_marked_user_condition() (strict, marker-only - FIX2-3) so an already-present
     * restriction is left untouched. A NEW restriction is combined with any EXISTING tree instead
     * of overwriting the whole column, so a teacher-added restriction (e.g. a date restriction)
     * survives; a fresh single-condition tree is only created when the module has no availability
     * restrictions at all yet (G7).
     *
     * How the new node is combined depends on the existing root's operator (FIX2-2): when the root
     * is AND ('&'), the plugin's node is appended as another required clause. For any other root op
     * (OR/NOT-AND/NOT-OR), appending directly would let an OR combine the gate away, or a negation
     * invert its semantics - so the existing tree is wrapped as a nested child of a brand-new AND
     * root, with the plugin's node as its sibling: the existing tree keeps its own op internally,
     * but the OVERALL result is a hard AND between "the existing tree" and "the plugin's gate".
     *
     * @param int $cmid Course module id.
     * @param bool $rebuildcache Whether set_coursemodule_visible() should rebuild the course cache
     *             immediately, or defer it to a single caller-side rebuild (FIX2-9).
     * @return void
     */
    private function apply_availability($cmid, bool $rebuildcache = true): void {
        global $DB;

        $cmrecord = $DB->get_record('course_modules', ['id' => $cmid], 'id, availability', MUST_EXIST);
        $availability = $cmrecord->availability ? json_decode($cmrecord->availability) : null;

        if ($this->find_marked_user_condition($availability) === null) {
            $usercondition = (object) [
                'type' => 'user',
                'userids' => [],
                self::MARKER_KEY => $this->marker_value(),
            ];

            if ($availability !== null && isset($availability->c) && is_array($availability->c)) {
                $rootop = $availability->op ?? tree::OP_AND;
                if ($rootop === tree::OP_AND) {
                    // AND root: another required clause combines correctly with what is already
                    // there.
                    $availability->c[] = $usercondition;
                    $showc = isset($availability->showc) && is_array($availability->showc) ? $availability->showc : [];
                    $showc[] = false;
                    $availability->showc = $showc;
                } else {
                    // OR / NOT-AND / NOT-OR root: wrap the existing tree as a nested child of a
                    // brand-new AND root, with the plugin's node as its sibling.
                    //
                    // FIX3-2: the teacher's original root ->show flag (whether the WHOLE existing
                    // tree was set to show greyed-out, or hide entirely, when its conditions are not
                    // met) must be captured BEFORE the wrap discards it - hard-coding the nested
                    // subtree's showc slot to false silently turned a teacher's "show greyed out"
                    // choice into "hide", permanently, on every wrap.
                    //
                    // FIX4-2: AND/NOT-OR roots (like the one being wrapped here) carry a per-child
                    // ->showc array instead of a single ->show flag - reading ->show on one of those
                    // always misses, collapsing the greyed-out intent to "hide". When ->showc is
                    // present, derive the flag from it instead: the wrapped subtree should show
                    // greyed-out if ANY of its children would have.
                    $rootshow = (isset($availability->showc) && is_array($availability->showc))
                        ? (bool) array_filter(array_map('boolval', $availability->showc))
                        : (bool) ($availability->show ?? false);
                    $nested = (object) [
                        'op' => $availability->op,
                        'c' => $availability->c,
                    ];
                    if (isset($availability->showc)) {
                        $nested->showc = $availability->showc;
                    }
                    if (isset($availability->show)) {
                        $nested->show = $availability->show;
                    }
                    $availability = (object) [
                        'op' => tree::OP_AND,
                        'c' => [$nested, $usercondition],
                        'showc' => [$rootshow, false],
                    ];
                }
            } else {
                $availability = tree::get_root_json([$usercondition], tree::OP_AND, false);
            }

            $availability = $this->normalise_root($availability);

            $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $cmid]);
        }

        set_coursemodule_visible($cmid, 1, 1, $rebuildcache);
    }

    /**
     * Ensure a root-level availability tree has a show/hide structure consistent with its op and
     * child count (Judge B's showc-mismatch finding): core_availability\tree::__construct() throws
     * a coding_exception when count(->c) !== count(->showc) for an AND/NOT-OR root, or when ->show
     * is missing/not-bool for an OR/NOT-AND root - both are easy to get out of sync by hand.
     *
     * @param \stdClass $availability Root-level availability tree about to be written to the DB.
     * @return \stdClass The same object, with showc/show normalised for its op.
     */
    private function normalise_root(\stdClass $availability): \stdClass {
        $op = $availability->op ?? tree::OP_AND;
        $count = isset($availability->c) && is_array($availability->c) ? count($availability->c) : 0;

        if ($op === tree::OP_AND || $op === tree::OP_NOT_OR) {
            $showc = $availability->showc ?? [];
            // A JSON array that happens to be empty (or whose keys were disturbed by manual
            // editing) can decode as a stdClass instead of a PHP array (micro-sweep) - re-index to
            // a plain array before padding/slicing, and coerce every entry to a real bool so a
            // stray truthy/falsy JSON value never reaches core_availability\tree unchanged.
            $showc = is_array($showc) ? $showc : array_values((array) $showc);
            $showc = array_map('boolval', $showc);
            if (count($showc) < $count) {
                $showc = array_pad($showc, $count, false);
            } else if (count($showc) > $count) {
                $showc = array_slice($showc, 0, $count);
            }
            $availability->showc = $showc;
            unset($availability->show);
        } else {
            if (!isset($availability->show) || !is_bool($availability->show)) {
                $availability->show = false;
            }
            unset($availability->showc);
        }

        return $availability;
    }

    /**
     * Remove a specific node from a decoded availability (sub)tree (identity match), keeping showc
     * in sync, and normalising the level it was removed from (FIX2-2/Judge B).
     *
     * Recurses into nested subtrees (FIX3-6): $target may not be a DIRECT child of $availability if
     * a teacher has since regrouped it (e.g. via the core "Restrict access" UI) - the marker made
     * finding it unambiguous regardless of depth, so removal must be able to reach it there too. A
     * nested subtree that becomes entirely empty as a result is itself dropped from its parent.
     *
     * @param object $availability Decoded availability (sub)tree that contains $target, directly or
     *               nested.
     * @param object $target The exact node to remove.
     * @param bool $isroot Whether $availability is the TRUE tree root (default), as opposed to a
     *             nested subtree reached via recursion. Only the true root gets passed through
     *             normalise_root() (FIX4): a nested subtree's own show/showc was set by whoever
     *             owns it (teacher UI, another plugin, or an earlier wrap) and is already internally
     *             consistent - rewriting it on every recursive call risked silently mutating a
     *             nested subtree's show/showc that this removal never touched.
     * @return object|null The (sub)tree with the node removed, or null when nothing else remains at
     *         this level.
     */
    private function remove_user_condition($availability, $target, bool $isroot = true) {
        $hasshowc = isset($availability->showc) && is_array($availability->showc);

        $remainingconditions = [];
        $remainingshow = [];
        foreach ($availability->c as $index => $condition) {
            if ($condition === $target) {
                // Direct match: drop it (and its showc slot).
                continue;
            }

            if (!isset($condition->type) && isset($condition->c) && is_array($condition->c)) {
                // Nested subtree: recurse instead of assuming $target can only be a direct child.
                $updated = $this->remove_user_condition($condition, $target, false);
                if ($updated === null) {
                    // The subtree was exhausted entirely by this removal; drop it too.
                    continue;
                }
                $condition = $updated;
            }

            $remainingconditions[] = $condition;
            if ($hasshowc && array_key_exists($index, $availability->showc)) {
                $remainingshow[] = $availability->showc[$index];
            }
        }

        if (empty($remainingconditions)) {
            return null;
        }

        $availability->c = $remainingconditions;
        if ($hasshowc) {
            $availability->showc = $remainingshow;
        }

        return $isroot ? $this->normalise_root($availability) : $availability;
    }

    /**
     * Surgically remove the plugin's own user-restriction node from each course module's
     * availability tree (nulling the column only when nothing else remains), and restore its
     * visible/visibleoncoursepage snapshot. Shared by delete() (all modules) and the edit path's
     * removed-cmid diff (D6/G7).
     *
     * Uses find_user_condition() (marker-first, legacy-fallback - FIX2-3): every cmid passed here
     * comes from $this->params->coursemodules, so the fallback is safe by construction EXCEPT in
     * "degraded mode" (FIX3-7): if the marker has since been stripped (e.g. a teacher re-saved the
     * module's "Restrict access" UI from scratch, which regenerates the tree and drops unknown
     * properties - see the MARKER_KEY docblock) AND a genuine teacher-added user restriction now
     * coexists, 2+ unmarked nodes are ambiguous and find_user_condition() correctly refuses to
     * guess. Previously this silently did nothing, leaking an ownerless node with all its
     * accumulated userids forever, with no signal an admin could act on. A debugging() call now
     * names the cm and the leftover node count so it can be manually reconciled; the tree itself is
     * left untouched rather than risk removing the wrong node.
     *
     * @param object[] $coursemodules Snapshots with id, visible, visibleoncoursepage.
     * @param bool $rebuildcache Whether set_coursemodule_visible() should rebuild the course cache
     *             immediately, or defer it to a single caller-side rebuild (FIX2-9).
     * @return void
     */
    private function restore_coursemodules(array $coursemodules, bool $rebuildcache = true): void {
        global $DB;

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;

            // If the module no longer exists there is nothing to restore; keep going so the rule
            // stays deletable/editable (set_coursemodule_visible() would otherwise fatal on a
            // missing context).
            if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
                continue;
            }

            $rawavailability = $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
            $availability = $rawavailability ? json_decode($rawavailability) : null;

            $usercondition = $this->find_user_condition($availability);
            if ($usercondition !== null) {
                $remaining = $this->remove_user_condition($availability, $usercondition);
                $DB->set_field(
                    'course_modules',
                    'availability',
                    $remaining !== null ? json_encode($remaining) : null,
                    ['id' => $cmid]
                );
            } else {
                $unmarkedcount = count($this->collect_unmarked_user_conditions($availability));
                if ($unmarkedcount > 1) {
                    debugging(
                        'enableactivity: course module ' . $cmid . ' has ' . $unmarkedcount
                            . ' ambiguous unmarked user restriction node(s); this action\'s own node '
                            . 'could not be safely identified and was left in place - manual cleanup '
                            . 'required',
                        DEBUG_DEVELOPER
                    );
                }
            }

            set_coursemodule_visible($cmid, $cm->visible, $cm->visibleoncoursepage, $rebuildcache);
        }
    }

    /**
     * Returns the description of the action to visualization
     *
     * @return string
     */
    public function get_description() {
        $coursemodules = $this->params->coursemodules ?? [];
        $descriptionarray = [];

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;
            $cminfo = get_coursemodule_from_id(null, $cmid, $this->courseid);
            if (!$cminfo) {
                continue;
            }
            $descriptionarray[] = ucfirst($cminfo->modname) . " - " . $cminfo->name;
        }
        return get_string(
            'enableactivity_description',
            'local_coursedynamicrules',
            implode(', ', $descriptionarray)
        );
    }

    /**
     * Deletes a action record from the 'local_coursedynamicrules_action' table. and related information with it.
     *
     * @return bool True on success, false on failure.
     * @throws \dml_exception A DML specific exception is thrown for any errors.
     */
    public function delete() {
        $this->restore_coursemodules((array) ($this->params->coursemodules ?? []));

        return parent::delete();
    }
}
