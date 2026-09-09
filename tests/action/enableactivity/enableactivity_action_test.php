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
use local_coursedynamicrules\form\actions\enableactivity_form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for the enableactivity action robustness against deleted/changed modules.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\action\enableactivity\enableactivity_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enableactivity_action_test extends \advanced_testcase {
    /**
     * Set the user-restriction availability tree the action expects on a module.
     *
     * @param int $cmid Course module id.
     * @return void
     */
    private function set_user_restriction(int $cmid): void {
        global $DB;
        $tree = tree::get_root_json([(object) ['type' => 'user', 'userids' => []]], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * Insert a rule row belonging to the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * MDL-INT-008: on edit, a removed activity recovers its visibility snapshot while retained ones keep granted access.
     *
     * Editing an enableactivity action must reconcile cmids without revoking access already granted
     * by execute() on a retained module, and must restore a deselected module's visible/
     * visibleoncoursepage snapshot (D6/blocker 3).
     *
     * @covers ::save_action
     */
    public function test_edit_reconciles_cmids_without_revoking_retained_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page1 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $page2 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        // Distinct initial visible state on page2, to prove the restore uses this exact snapshot.
        set_coursemodule_visible($page2->cmid, 0, 0);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page1->cmid, $page2->cmid],
        ]);
        $id = $action->get_id();

        // Simulate a prior rule execution granting access to a real user on both retained modules.
        $grantee = $this->getDataGenerator()->create_user();
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);

        // Edit: deselect page2, keep page1.
        $stored = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editaction = new enableactivity_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page1->cmid],
        ]);

        $this->assertEquals(1, $DB->count_records(action::TABLE, ['id' => $id]));

        // Retained module: access already granted by execute() must not be wiped.
        $availability1 = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page1->cmid]));
        $this->assertContains($grantee->id, $availability1->c[0]->userids);

        // Removed module: availability cleared, visible/visibleoncoursepage restored to snapshot.
        $this->assertNull($DB->get_field('course_modules', 'availability', ['id' => $page2->cmid]));
        $cm2 = $DB->get_record('course_modules', ['id' => $page2->cmid], '*', MUST_EXIST);
        $this->assertEquals(0, $cm2->visible);
        $this->assertEquals(0, $cm2->visibleoncoursepage);

        $storedparams = json_decode($DB->get_field(action::TABLE, 'params', ['id' => $id]));
        $this->assertCount(1, $storedparams->coursemodules);
        $this->assertEquals($page1->cmid, $storedparams->coursemodules[0]->id);
    }

    /**
     * MDL-UNIT-017: on an AND root the own user node is merged as an extra clause, preserving the teacher's restriction.
     *
     * Adding a module with a PRE-EXISTING manual restriction (e.g. a teacher-added date
     * restriction) must not overwrite the whole availability column: the plugin's own user
     * restriction is merged in alongside it, and the manual restriction survives untouched (G7).
     *
     * @covers ::save_action
     */
    public function test_save_action_new_cmid_preserves_existing_manual_restriction(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $manualtree = tree::get_root_json([$datecondition], tree::OP_AND, true);
        $DB->set_field('course_modules', 'availability', json_encode($manualtree), ['id' => $page->cmid]);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        $this->assertCount(2, $availability->c);
        $types = array_map(fn($condition) => $condition->type, $availability->c);
        $this->assertContains('date', $types);
        $this->assertContains('user', $types);

        $datenode = $availability->c[array_search('date', $types)];
        $this->assertSame('>=', $datenode->d);
        $this->assertEquals(1735689600, $datenode->t);

        $usernode = $availability->c[array_search('user', $types)];
        $this->assertSame([], $usernode->userids);
    }

    /**
     * MDL-UNIT-017: a single unmarked user node is adopted as own and removed, leaving the teacher's restriction untouched.
     *
     * Deleting an enableactivity action must remove ONLY the plugin's own user-type node from the
     * availability tree, leaving an unrelated manual restriction (e.g. a date restriction) intact
     * instead of nulling the whole column (G7).
     *
     * @covers ::delete
     */
    public function test_delete_removes_only_plugin_node_and_keeps_manual_restriction(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $usercondition = (object) ['type' => 'user', 'userids' => [42]];
        $tree = tree::get_root_json([$datecondition, $usercondition], tree::OP_AND, [true, false]);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $page->cmid]);

        $record = (object) [
            'ruleid' => 1,
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]]]),
        ];
        $record->id = $DB->insert_record('local_coursedynamicrules_action', $record);

        $action = new enableactivity_action($record, $course->id);
        $action->delete();

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        $this->assertNotNull($availability);
        $this->assertCount(1, $availability->c);
        $this->assertSame('date', $availability->c[0]->type);
        $this->assertSame('>=', $availability->c[0]->d);
    }

    /**
     * MDL-UNIT-017: an existing OR root is wrapped under a new AND root so the plugin's gate cannot be OR-ed away.
     *
     * FIX2-2: when the existing tree's root operator is OR ('|'), appending the plugin's user
     * node directly into the same root would let the OR combine it away - the gate would be
     * satisfied (and the module shown) whenever the OTHER branch passes, even for a user the
     * plugin never granted access to. The plugin's node must be combined via AND instead: wrap the
     * existing OR-tree as a nested child alongside the plugin's node under a brand-new AND root.
     *
     * @covers ::save_action
     */
    public function test_save_action_new_cmid_wraps_existing_or_root_instead_of_oring_the_gate_away(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        // A teacher-configured OR root: "available if EITHER the date passed OR the group
        // matches" (contrived but structurally valid; what matters is the root op is '|').
        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $groupcondition = (object) ['type' => 'group', 'id' => 0];
        $orroot = tree::get_root_json([$datecondition, $groupcondition], tree::OP_OR, true);
        $DB->set_field('course_modules', 'availability', json_encode($orroot), ['id' => $page->cmid]);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        // The new root must be a hard AND between "the existing OR-tree" and "the plugin's gate",
        // never a direct append into the OR (which would let the gate be OR-ed away).
        $this->assertSame(tree::OP_AND, $availability->op);
        $this->assertCount(2, $availability->c);
        $this->assertCount(2, $availability->showc);

        // FIX3-2: the teacher's original root ->show (true - "show greyed out") must be preserved
        // into the nested subtree's showc slot (index 0, since 'c' => [$nested, $usercondition]),
        // not hard-coded to false - the plugin's own gate (index 1) is always hidden (false).
        $this->assertSame([true, false], $availability->showc);

        $usernode = null;
        $nestedtree = null;
        foreach ($availability->c as $child) {
            if (isset($child->type) && $child->type === 'user') {
                $usernode = $child;
            } else {
                $nestedtree = $child;
            }
        }

        $this->assertNotNull($usernode, 'The plugin user gate must be a direct child of the new AND root.');
        $this->assertSame([], $usernode->userids);

        $this->assertNotNull($nestedtree, 'The existing OR-tree must survive, nested.');
        $this->assertSame(tree::OP_OR, $nestedtree->op);
        $this->assertCount(2, $nestedtree->c);

        // The whole structure must be decodable by core without a coding_exception (proves showc
        // is never mismatched with c - Judge B's finding).
        // FIX3-1: $lax = true - the assertions below are about tree STRUCTURE (c/showc counts),
        // which still validate under lax decoding; strict decoding would throw in CI if the
        // third-party availability_user plugin is not installed there.
        $decodedtree = new tree($availability, true, true);
        $this->assertInstanceOf(tree::class, $decodedtree);
    }

    /**
     * MDL-UNIT-017: a negated NOT-AND root is wrapped under a new AND root with a normalised showc array.
     *
     * FIX2-2: same wrapping behaviour for a NOT-AND ('!&') root, which uses a single 'show' bool
     * rather than a showc array - the wrap must still normalise the new AND root to a proper showc
     * array, not carry over the old 'show' semantics.
     *
     * @covers ::save_action
     */
    public function test_save_action_new_cmid_wraps_existing_notand_root(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $notandroot = tree::get_root_json([$datecondition], tree::OP_NOT_AND, true);
        $DB->set_field('course_modules', 'availability', json_encode($notandroot), ['id' => $page->cmid]);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        $this->assertSame(tree::OP_AND, $availability->op);
        $this->assertCount(2, $availability->c);
        $this->assertTrue(property_exists($availability, 'showc'), 'AND root must carry showc.');
        $this->assertCount(2, $availability->showc);
        $this->assertFalse(property_exists($availability, 'show'), 'AND root must not carry a stale show bool.');

        // FIX3-2: the teacher's original root ->show (true) must be preserved into the nested
        // subtree's showc slot (index 0), not hard-coded to false.
        $this->assertSame([true, false], $availability->showc);

        // FIX3-1: $lax = true - the assertions below are about tree STRUCTURE (c/showc counts),
        // which still validate under lax decoding; strict decoding would throw in CI if the
        // third-party availability_user plugin is not installed there.
        $decodedtree = new tree($availability, true, true);
        $this->assertInstanceOf(tree::class, $decodedtree);
    }

    /**
     * MDL-UNIT-017: a negated NOT-OR root is wrapped, deriving the show flag from its per-child showc array.
     *
     * FIX4-2: a NOT-OR ('!|') root carries a PER-CHILD ->showc array (like AND), not a single
     * ->show bool (like OR/NOT-AND). Reading ->show on a NOT-OR root always misses (it is never
     * set), so the previous code collapsed the teacher's "show greyed out" choice to "hide" on
     * every wrap of a NOT-OR root. The wrap must derive the flag from ->showc instead: true if ANY
     * child was set to show greyed-out.
     *
     * @covers ::save_action
     */
    public function test_save_action_new_cmid_wraps_existing_notor_root_derives_show_from_showc(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        // A NOT-OR root with a single child, showc = [true] ("show greyed out" for that child).
        // NOT-OR has no ->show property at all - only ->showc.
        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $notorroot = tree::get_root_json([$datecondition], tree::OP_NOT_OR, [true]);
        $DB->set_field('course_modules', 'availability', json_encode($notorroot), ['id' => $page->cmid]);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        $this->assertSame(tree::OP_AND, $availability->op);
        $this->assertCount(2, $availability->c);
        $this->assertCount(2, $availability->showc);

        // The teacher's NOT-OR root had showc = [true] (at least one child shows greyed out) -
        // that must survive into the new AND root's showc[0]. Before FIX4-2 this was always
        // false, because the old code only ever read the (non-existent) ->show property.
        $this->assertSame([true, false], $availability->showc);

        $nestedtree = null;
        foreach ($availability->c as $child) {
            if (!isset($child->type)) {
                $nestedtree = $child;
            }
        }
        $this->assertNotNull($nestedtree, 'The existing NOT-OR tree must survive, nested.');
        $this->assertSame(tree::OP_NOT_OR, $nestedtree->op);
        $this->assertSame([true], $nestedtree->showc);

        // FIX3-1: $lax = true (see the analogous comment on the other wrap tests above).
        $decodedtree = new tree($availability, true, true);
        $this->assertInstanceOf(tree::class, $decodedtree);
    }

    /**
     * MDL-UNIT-017: after wrapping a non-AND root, removing the own node unwraps to a structurally valid tree.
     *
     * FIX2-2/FIX2-3: after wrapping an existing non-AND root, removing the plugin's own node
     * (delete()/edit's removed-cmid path) must leave a STILL-VALID tree behind (root op/showc
     * consistent with the remaining children), not a structurally broken one.
     *
     * @covers ::save_action
     */
    public function test_removing_after_wrap_leaves_a_structurally_valid_tree(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $orroot = tree::get_root_json([$datecondition], tree::OP_OR, true);
        $DB->set_field('course_modules', 'availability', json_encode($orroot), ['id' => $page->cmid]);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        // Edit: deselect the only module, triggering the removed-cmid restore path.
        $stored = $DB->get_record(action::TABLE, ['id' => $action->get_id()], '*', MUST_EXIST);
        $editaction = new enableactivity_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [],
        ]);

        $final = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        $this->assertNotNull($final);
        // FIX3-1: $lax = true (see the analogous comment on the wrap tests above).
        $decodedtree = new tree($final, true, true);
        $this->assertInstanceOf(tree::class, $decodedtree);

        // Only the nested OR-tree (containing the date restriction) remains; the plugin's node
        // is gone.
        $this->assertCount(1, $final->c);
        $remainingchild = $final->c[0];
        $this->assertFalse(property_exists($remainingchild, 'type'), 'The remaining child must be the nested subtree.');
        $this->assertSame(tree::OP_OR, $remainingchild->op);

        // FIX3-2: the nested subtree's own showc must have survived the wrap/removal round-trip
        // untouched (it was seeded from the OR-root's ->show flag; here show=true was passed to
        // get_root_json(), which the ORIGINAL wrap step - apply_availability() - must have captured
        // into the new AND root's showc[0] BEFORE it got nested).
        $this->assertTrue(property_exists($final, 'showc'));
        $this->assertSame([true], $final->showc);
    }

    /**
     * MDL-UNIT-017: the marker distinguishes the plugin's own node from a teacher-added user node so each is handled independently.
     *
     * FIX2-3: a teacher-added user restriction (a genuine `availability_user` restriction added
     * independently via the "Restrict access" UI) must coexist with the plugin's own node: adding/
     * removing the plugin's gate must not touch the teacher's node, and execute() must inject the
     * matched user id only into the plugin's own (marked) node.
     *
     * @covers ::save_action
     * @covers ::execute
     */
    public function test_plugin_gate_coexists_with_a_teacher_added_user_restriction(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        // A teacher independently restricted the module to a specific user via the core UI - an
        // UNMARKED 'user' node, structurally identical to the plugin's own before it is created.
        $teacheruserid = 4242;
        $teachercondition = (object) ['type' => 'user', 'userids' => [$teacheruserid]];
        $tree = tree::get_root_json([$teachercondition], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $page->cmid]);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertCount(2, $availability->c);

        // Neither user node's userids array changed: the teacher's node still has ONLY its user,
        // and the plugin's own node was added empty alongside it.
        $usernodes = $availability->c;
        $teachernode = null;
        $pluginnode = null;
        foreach ($usernodes as $node) {
            if (in_array($teacheruserid, $node->userids, true)) {
                $teachernode = $node;
            } else {
                $pluginnode = $node;
            }
        }
        $this->assertNotNull($teachernode, 'The teacher-added node must be untouched.');
        $this->assertSame([$teacheruserid], $teachernode->userids);
        $this->assertNotNull($pluginnode, 'The plugin must have added its own node.');
        $this->assertSame([], $pluginnode->userids);

        // Execute() must inject the matched user ONLY into the plugin's own node.
        $grantee = $this->getDataGenerator()->create_user();
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);

        $availabilityafterexecute = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        foreach ($availabilityafterexecute->c as $node) {
            if (in_array($teacheruserid, $node->userids, true)) {
                // Teacher's node: untouched, must NOT have gained the grantee.
                $this->assertNotContains($grantee->id, $node->userids);
                $this->assertSame([$teacheruserid], $node->userids);
            } else {
                // Plugin's node: must now contain the grantee.
                $this->assertContains($grantee->id, $node->userids);
            }
        }

        // Delete() must remove ONLY the plugin's own node, leaving the teacher's node intact.
        $action->delete();
        $availabilityafterdelete = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertCount(1, $availabilityafterdelete->c);
        $this->assertSame([$teacheruserid], $availabilityafterdelete->c[0]->userids);
    }

    /**
     * MDL-INT-008: on save the activity is resolved and snapshotted against the action's own course, not client formdata.
     *
     * FIX2-4: save_action() must resolve newly-added course modules against $this->courseid (the
     * course the action instance is bound to), not the client-controlled $formdata->courseid - a
     * mismatched/bogus formdata->courseid must not corrupt the snapshot.
     *
     * @covers ::save_action
     */
    public function test_save_action_uses_own_courseid_not_client_supplied_formdata_courseid(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        set_coursemodule_visible($page->cmid, 0, 0);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);

        // A bogus/foreign courseid in formdata must not prevent the real cm (in the action's OWN
        // course) from being resolved and snapshotted correctly.
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => 999999,
            'coursemodules' => [$page->cmid],
        ]);

        $storedparams = json_decode($DB->get_field(action::TABLE, 'params', ['id' => $action->get_id()]));
        $this->assertCount(1, $storedparams->coursemodules);
        $this->assertSame($page->cmid, $storedparams->coursemodules[0]->id);
        // The snapshot must reflect the module's REAL prior state (invisible), proving it was
        // resolved via the real course, not silently defaulted/corrupted.
        $this->assertEquals(0, $storedparams->coursemodules[0]->visible);
        $this->assertEquals(0, $storedparams->coursemodules[0]->visibleoncoursepage);
    }

    /**
     * MDL-INT-008: an unresolvable activity id is skipped on save with a debugging() call, without fataling.
     *
     * FIX2-4: a course module id that does not resolve at all (bogus/tampered id, or a race where
     * it was deleted between form render and submit) must be skipped with a debugging() call
     * instead of fataling on a false get_coursemodule_from_id() result.
     *
     * @covers ::save_action
     */
    public function test_save_action_skips_unresolvable_new_cmid_without_fatal(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);

        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [999999],
        ]);

        $this->assertDebuggingCalled();
        $storedparams = json_decode($DB->get_field(action::TABLE, 'params', ['id' => $action->get_id()]));
        $this->assertCount(0, $storedparams->coursemodules);
    }

    /**
     * MDL-INT-008: a multi-activity save gates and makes visible every configured activity consistently.
     *
     * FIX2-9: a multi-module save must leave params and module state fully consistent (both new
     * modules snapshotted/gated, batched as a single reconciliation) - a regression test for the
     * transactional/no-N+1-rebuild reconciliation, at the unit level this suite can reach.
     *
     * @covers ::save_action
     */
    public function test_save_action_multi_module_save_is_fully_consistent(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page1 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $page2 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page1->cmid, $page2->cmid],
        ]);

        $storedparams = json_decode($DB->get_field(action::TABLE, 'params', ['id' => $action->get_id()]));
        $this->assertCount(2, $storedparams->coursemodules);

        foreach ([$page1->cmid, $page2->cmid] as $cmid) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $this->assertEquals(1, $cm->visible);
            $availability = json_decode($cm->availability);
            $this->assertNotNull($availability);
            $usertypes = array_filter($availability->c, fn($condition) => ($condition->type ?? null) === 'user');
            $this->assertCount(1, $usertypes);
        }
    }

    /**
     * MDL-UNIT-017: removing the own node on edit preserves an unrelated manual restriction instead of nulling the tree.
     *
     * Deselecting a module on edit (the removed-cmid diff, shared with delete()'s restore path)
     * must also preserve an unrelated manual restriction instead of nulling the whole column (G7).
     *
     * @covers ::save_action
     */
    public function test_save_action_removed_cmid_preserves_existing_manual_restriction(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        // A teacher adds a manual date restriction alongside the plugin's own node.
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $datecondition = (object) ['type' => 'date', 'd' => '>=', 't' => 1735689600];
        $availability->c[] = $datecondition;
        $availability->showc[] = true;
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $page->cmid]);

        // Edit: deselect the only module.
        $stored = $DB->get_record(action::TABLE, ['id' => $action->get_id()], '*', MUST_EXIST);
        $editaction = new enableactivity_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [],
        ]);

        $final = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));

        $this->assertNotNull($final);
        $this->assertCount(1, $final->c);
        $this->assertSame('date', $final->c[0]->type);
    }

    /**
     * MDL-UNIT-017: each action gets its own identity-bearing marked node so two actions on one module never cross-revoke.
     *
     * FIX3-3: the marker used to be a single constant shared by EVERY enableactivity action, so two
     * different actions gating the SAME course module ended up sharing one node - deleting either
     * action's grants cross-revoked the other's. Each action must get its OWN, identity-bearing
     * node, so removing one never touches the other's node or grants.
     *
     * @covers ::save_action
     * @covers ::execute
     * @covers ::delete
     */
    public function test_two_actions_on_same_cm_do_not_cross_revoke_each_others_grants(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleida = $this->create_rule($course->id);
        $ruleidb = $this->create_rule($course->id);

        $recorda = (object) ['id' => null, 'ruleid' => $ruleida, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $actiona = new enableactivity_action($recorda, $course->id);
        $actiona->save_action((object) ['ruleid' => $ruleida, 'courseid' => $course->id, 'coursemodules' => [$page->cmid]]);

        $recordb = (object) ['id' => null, 'ruleid' => $ruleidb, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $actionb = new enableactivity_action($recordb, $course->id);
        $actionb->save_action((object) ['ruleid' => $ruleidb, 'courseid' => $course->id, 'coursemodules' => [$page->cmid]]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertCount(2, $availability->c, "Each action must get its OWN node instead of sharing one.");

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $actiona->execute((object) ['courseid' => $course->id, 'userid' => $usera->id]);
        $actionb->execute((object) ['courseid' => $course->id, 'userid' => $userb->id]);

        // Deleting A must remove ONLY A's node/grant, leaving B's node and grant fully intact.
        $actiona->delete();

        $final = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertCount(1, $final->c, "Only A's node must be removed.");
        $this->assertContains($userb->id, $final->c[0]->userids);
        $this->assertNotContains($usera->id, $final->c[0]->userids);
    }

    /**
     * MDL-UNIT-017: the own marked node is found and reused even when nested under a teacher grouping, with no duplicate gate appended.
     *
     * FIX3-6: a teacher grouping restrictions via the core "Restrict access" UI can nest this
     * action's own (marked) node inside a child subtree instead of leaving it a direct root child.
     * Previously only the top level was searched, so the action would go inert (execute() could no
     * longer find its own node) and re-reconciling would append a SECOND, empty gate alongside the
     * nested one. The marker makes matching unambiguous regardless of depth, so both execute() and
     * apply_availability()'s "does a gate already exist" check must recurse.
     *
     * @covers ::execute
     * @covers ::save_action
     */
    public function test_finds_and_reuses_own_node_when_nested_under_a_teacher_grouping(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) ['ruleid' => $ruleid, 'courseid' => $course->id, 'coursemodules' => [$page->cmid]]);

        // Simulate the core "Restrict access" UI re-grouping this action's own (marked) node under
        // an extra nested subtree - the marker survives the regroup, just no longer at the top
        // level.
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $ownnode = $availability->c[0];
        $nested = (object) ['op' => tree::OP_OR, 'c' => [$ownnode], 'show' => true];
        $regrouped = (object) ['op' => tree::OP_AND, 'c' => [$nested], 'showc' => [false]];
        $DB->set_field('course_modules', 'availability', json_encode($regrouped), ['id' => $page->cmid]);

        // Execute() must still find the (nested) node and grant access, instead of going inert.
        $grantee = $this->getDataGenerator()->create_user();
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);

        $after = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertContains($grantee->id, $after->c[0]->c[0]->userids);
        $this->assertDebuggingNotCalled();

        // Re-running the reconciliation for the SAME cmid must NOT append a second, empty gate
        // alongside the nested one: the marker exists, just nested, and find_marked_user_condition()
        // must find it there. apply_availability() is private; its only public caller is
        // save_action(), which re-applies the gate only for a cmid it treats as newly-added.
        // Reload the action from its stored row with the coursemodules snapshot cleared, so the
        // (still-gated) cmid is seen as new and apply_availability() runs again over the regrouped
        // tree - through the public entry point, with the same action id (hence the same marker).
        $stored = $DB->get_record(action::TABLE, ['id' => $action->get_id()], '*', MUST_EXIST);
        $stored->params = json_encode([]);
        $resaveaction = new enableactivity_action($stored, $course->id);
        $resaveaction->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$page->cmid],
        ]);

        $final = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertCount(1, $final->c, 'No second gate must be appended: the marker exists, just nested.');
    }

    /**
     * MDL-INT-009: with two or more unmarked user nodes the ambiguous case is reported without guessing or mutating either node.
     *
     * FIX3-7: when the marker has been stripped (e.g. a teacher re-saved the module's "Restrict
     * access" UI from scratch, which regenerates the tree and drops unknown properties) AND a
     * genuine teacher-added user restriction now coexists, 2+ unmarked nodes are ambiguous -
     * find_user_condition() correctly refuses to guess which one is this action's own. Previously
     * this silently did nothing on delete()/edit, leaking an ownerless node with its accumulated
     * userids forever. A debugging() call must now signal this, naming the cm, and neither node may
     * be mutated.
     *
     * @covers ::delete
     */
    public function test_restore_degraded_mode_leaves_ambiguous_nodes_untouched_and_warns(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule($course->id);

        // Two unmarked 'user' nodes: one is a genuine teacher restriction, the other is this
        // action's own leftover node from before the marker was stripped.
        $teachernode = (object) ['type' => 'user', 'userids' => [4242]];
        $leftovernode = (object) ['type' => 'user', 'userids' => [99]];
        $tree = tree::get_root_json([$teachernode, $leftovernode], tree::OP_AND, [false, false]);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $page->cmid]);

        $record = (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'enableactivity',
            'params' => json_encode([
                'coursemodules' => [(object) ['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]],
            ]),
        ];
        $record->id = $DB->insert_record(action::TABLE, $record);

        $action = new enableactivity_action($record, $course->id);
        $action->delete();

        $this->assertDebuggingCalled();
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertCount(2, $availability->c);
        $this->assertSame([4242], $availability->c[0]->userids);
        $this->assertSame([99], $availability->c[1]->userids);
    }

    /**
     * Build an enableactivity action for the given course modules.
     *
     * @param array $coursemodules Array of [id, visible, visibleoncoursepage].
     * @param int $courseid Course id.
     * @return enableactivity_action
     */
    private function create_action(array $coursemodules, int $courseid): enableactivity_action {
        $record = (object) [
            'ruleid' => 1,
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => $coursemodules]),
        ];
        return new enableactivity_action($record, $courseid);
    }

    /**
     * MDL-INT-008: on execute the matched student is added to the activity's user restriction list.
     *
     * Normal case: the matched user is added to the module's user restriction.
     *
     * @covers ::execute
     */
    public function test_execute_adds_user_to_restriction(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->set_user_restriction($page->cmid);

        $action = $this->create_action(
            [['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertContains($user->id, $availability->c[0]->userids);
        $this->assertDebuggingNotCalled();
    }

    /**
     * MDL-INT-008: on execute the student is granted even when the user restriction is not the first condition.
     *
     * The user restriction is found and updated even when it is not the first condition.
     *
     * A teacher may add another restriction (e.g. a date restriction) that shifts the plugin's user
     * restriction off index 0; the action must still locate it instead of silently skipping.
     *
     * @covers ::execute
     */
    public function test_execute_finds_user_restriction_not_at_index_zero(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();

        // A non-user restriction sits before the plugin's user restriction.
        $tree = tree::get_root_json([
            (object) ['type' => 'date', 'd' => '>=', 't' => 0],
            (object) ['type' => 'user', 'userids' => []],
        ], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $page->cmid]);

        $action = $this->create_action(
            [['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        // The date restriction is untouched; the user is added to the user node.
        $this->assertSame('date', $availability->c[0]->type);
        $this->assertContains($user->id, $availability->c[1]->userids);
        $this->assertDebuggingNotCalled();
    }

    /**
     * MDL-INT-008: a configured activity that no longer exists is skipped on execute without affecting the others.
     *
     * A deleted module must be skipped without a fatal error, and later modules still processed.
     *
     * @covers ::execute
     */
    public function test_execute_skips_deleted_module_and_continues(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->set_user_restriction($page->cmid);

        $action = $this->create_action(
            [
                ['id' => 999999, 'visible' => 0, 'visibleoncoursepage' => 0],
                ['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1],
            ],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalled();
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertContains($user->id, $availability->c[0]->userids);
    }

    /**
     * The runtime half of the same decision: an activity being deleted must not be written to.
     *
     * can_act() is consulted only by rule_lock::is_complete(), i.e. from the rule form and the
     * activation endpoint, so a rule that was already active when the teacher sent an activity to
     * the recycle bin keeps running. execute() is the only thing standing between the engine and a
     * module its own description already reports as gone.
     *
     * @covers ::execute
     */
    public function test_execute_does_not_write_to_an_activity_being_deleted(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $doomed = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $healthy = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->set_user_restriction($doomed->cmid);
        $this->set_user_restriction($healthy->cmid);

        $action = $this->create_action(
            [
                ['id' => $doomed->cmid, 'visible' => 1, 'visibleoncoursepage' => 1],
                ['id' => $healthy->cmid, 'visible' => 1, 'visibleoncoursepage' => 1],
            ],
            $course->id
        );

        course_delete_module((int) $doomed->cmid, true);
        $this->assertEquals(
            1,
            $DB->get_field('course_modules', 'deletioninprogress', ['id' => $doomed->cmid]),
            'Precondition: the deletion must be in progress, not finished.'
        );

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $doomedjson = $DB->get_field('course_modules', 'availability', ['id' => $doomed->cmid]);
        $this->assertNotEmpty($doomedjson, 'Precondition: the recycle bin leaves the restriction in place.');
        $doomedtree = json_decode($doomedjson);
        $this->assertNotContains(
            $user->id,
            $doomedtree->c[0]->userids,
            'The engine must not open an activity its own description reports as gone.'
        );

        $healthytree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $healthy->cmid]));
        $this->assertContains(
            $user->id,
            $healthytree->c[0]->userids,
            'And the intact activity is still opened, so the filter cannot pass by skipping everything.'
        );

        $this->assertDebuggingCalled();
    }

    /**
     * MDL-INT-008: an activity whose availability was cleared is skipped on execute without corrupting it.
     *
     * A module whose availability was cleared must be skipped without corrupting it.
     *
     * @covers ::execute
     */
    public function test_execute_skips_when_availability_null(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('course_modules', 'availability', null, ['id' => $page->cmid]);

        $action = $this->create_action(
            [['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalled();
        $this->assertNull($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
    }

    /**
     * MDL-INT-009: the action is deletable even when a managed activity no longer exists.
     *
     * The rule/action must be deletable even when a referenced module no longer exists.
     *
     * @covers ::delete
     */
    public function test_delete_succeeds_when_module_deleted(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $record = (object) [
            'ruleid' => 1,
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [['id' => 999999, 'visible' => 0, 'visibleoncoursepage' => 0]]]),
        ];
        $record->id = $DB->insert_record('local_coursedynamicrules_action', $record);

        $action = new enableactivity_action($record, $course->id);
        $action->delete();

        $this->assertFalse($DB->record_exists('local_coursedynamicrules_action', ['id' => $record->id]));
    }

    /**
     * The description must skip deleted modules without warnings.
     *
     * @covers ::get_description
     */
    public function test_get_description_skips_deleted_module(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $action = $this->create_action(
            [['id' => 999999, 'visible' => 0, 'visibleoncoursepage' => 0]],
            $course->id
        );

        $description = $action->get_description();

        $this->assertIsString($description);
        $this->assertDebuggingNotCalled();
    }
    /**
     * An action with nothing to name says which of the two reasons applies, instead of rendering
     * "Enable activities ''" - empty quotes that told the operator nothing. No activity chosen yet is
     * the state every duplicated copy is born in; every chosen activity deleted since is the ghost
     * case, which borrows the warning the activity conditions use.
     *
     * @covers ::get_description
     */
    public function test_an_action_with_nothing_to_name_says_why(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule((int) $course->id);

        // Nothing chosen yet: the copy's state, and the one the teacher must fix.
        $empty = new enableactivity_action(
            (object) ['ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode(['coursemodules' => []])],
            (int) $course->id
        );
        $this->assertSame(
            get_string('enableactivity_noactivities', 'local_coursedynamicrules'),
            $empty->get_description()
        );

        // Chosen and then deleted: the ghost case, named with the shared missing-activity warning.
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ghost = new enableactivity_action(
            (object) [
                'ruleid' => $ruleid,
                'actiontype' => 'enableactivity',
                'params' => json_encode(['coursemodules' => [(object) ['id' => $page->cmid]]]),
            ],
            (int) $course->id
        );
        $this->assertStringContainsString(
            $page->name,
            $ghost->get_description(),
            'Sanity: while the activity exists the action names it.'
        );

        course_delete_module((int) $page->cmid);
        rebuild_course_cache((int) $course->id, true);
        $this->assertSame(
            get_string('componenttargetmissing', 'local_coursedynamicrules'),
            $ghost->get_description(),
            'An action whose every activity is gone says so, instead of showing empty quotes.'
        );
    }
    /**
     * The clash query behind the form's refusal: an activity already gated by ANOTHER action of this
     * plugin cannot be shared, because Moodle ANDs the two gates and the newcomer's is empty until it
     * runs - so saving it takes the activity away from the first action's students. The action's OWN
     * gate is not a clash (that is just an edit), and a teacher's own restriction is not either.
     *
     * @covers ::modules_gated_by_another_action
     */
    public function test_a_module_gated_by_another_action_is_reported_as_a_clash(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $gated = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $free = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $manual = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        // A teacher's own user restriction: unmarked, and none of this plugin's business.
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json([(object) ['type' => 'user', 'userids' => [7]]], tree::OP_AND, false)),
            ['id' => $manual->cmid]
        );

        $ruleid = $this->create_rule((int) $course->id);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $owner = new enableactivity_action($record, (int) $course->id);
        $owner->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$gated->cmid],
        ]);
        $ownerid = (int) $owner->get_id();

        // Another action asking: the gated module clashes, the untouched ones do not.
        $this->assertSame(
            [(int) $gated->cmid],
            enableactivity_action::modules_gated_by_another_action(
                [(int) $gated->cmid, (int) $free->cmid, (int) $manual->cmid],
                (int) $course->id,
                $ownerid + 1000
            ),
            'Only a module carrying another action\'s own gate clashes.'
        );

        // The owner asking about its own module: an edit, not a clash.
        $this->assertSame(
            [],
            enableactivity_action::modules_gated_by_another_action([(int) $gated->cmid], (int) $course->id, $ownerid),
            'An action editing its own selection must not be refused its own activities.'
        );

        // A brand-new action (no id yet) is told the truth: the module is taken.
        $this->assertSame(
            [(int) $gated->cmid],
            enableactivity_action::modules_gated_by_another_action([(int) $gated->cmid], (int) $course->id, null)
        );

        // The state earlier versions allowed and the marker was designed for: TWO actions gating one
        // module. Each must stay able to save its own selection - refusing it would leave the action
        // unsavable, and its partner may be sealed and unable to release the module at all.
        $second = new enableactivity_action(
            (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])],
            (int) $course->id
        );
        $second->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$free->cmid],
        ]);
        // Give the second action a gate on the SAME module, the way a pre-1.8.4 site holds one.
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json([
                (object) ['type' => 'user', 'userids' => [], 'source' => 'local_coursedynamicrules:' . $ownerid],
                (object) ['type' => 'user', 'userids' => [], 'source' => 'local_coursedynamicrules:' . $second->get_id()],
            ], tree::OP_AND, false)),
            ['id' => $gated->cmid]
        );

        $this->assertSame(
            [],
            enableactivity_action::modules_gated_by_another_action([(int) $gated->cmid], (int) $course->id, $ownerid),
            'A module this action already gates is never a clash, whoever else gates it.'
        );
        $this->assertSame(
            [],
            enableactivity_action::modules_gated_by_another_action(
                [(int) $gated->cmid],
                (int) $course->id,
                (int) $second->get_id()
            ),
            'And the same for its partner: a pre-existing pair stays editable on both sides.'
        );
        $this->assertSame(
            [(int) $gated->cmid],
            enableactivity_action::modules_gated_by_another_action([(int) $gated->cmid], (int) $course->id, $ownerid + 5000),
            'A THIRD action is still refused: it would add a gate where it has none.'
        );

        // A gate whose owner no longer exists - what a course import leaves behind, since it brings
        // activities without rules - is not a clash: nothing can release it, no screen reaches it,
        // and refusing on its account would make the activity permanently unusable by the plugin.
        $orphan = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json([
                (object) ['type' => 'user', 'userids' => [], 'source' => 'local_coursedynamicrules:' . ($ownerid + 9999)],
            ], tree::OP_AND, false)),
            ['id' => $orphan->cmid]
        );

        $this->assertSame(
            [],
            enableactivity_action::modules_gated_by_another_action([(int) $orphan->cmid], (int) $course->id, null),
            'A marker naming an action that no longer exists must not lock the activity forever.'
        );
    }

    /**
     * And the form refuses that selection, naming the activity, instead of saving it and closing the
     * activity for the other action's students.
     *
     * @covers \local_coursedynamicrules\form\actions\enableactivity_form::validation
     */
    public function test_the_form_refuses_an_activity_another_action_already_opens(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; the action requires it.');
        }

        $course = $this->getDataGenerator()->create_course();
        $gated = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        // The activity the owner will ADD to its own selection: untouched by anyone.
        $free = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->create_rule((int) $course->id);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $owner = new enableactivity_action($record, (int) $course->id);
        $owner->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$gated->cmid],
        ]);

        // A NEW action reaching for that activity is refused through the real path: the form the
        // action builds for itself (the only place its id is injected), a submission, and get_data()
        // answering null - exactly what actions.php does. The message names the activity, on screen.
        $newaction = new enableactivity_action(
            (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])],
            (int) $course->id
        );
        enableactivity_form::mock_submit([
            'coursemodules' => [(int) $gated->cmid],
            'courseid' => (int) $course->id,
            'ruleid' => $ruleid,
            'type' => 'enableactivity',
        ]);
        $newaction->build_editform(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => (int) $course->id, 'ruleid' => $ruleid, 'type' => 'enableactivity']
        );
        $this->assertNull($newaction->get_data(), 'A clashing selection must not be saveable.');
        ob_start();
        $newaction->show_editform();
        $html = ob_get_clean();
        $this->assertStringContainsString($gated->name, $html, 'The refusal names the activity.');

        // And the OWNER editing its own selection is NOT refused its own activity: the everyday flow
        // of adding a second activity to an existing action. Its id reaches the form through
        // build_editform(), so deleting that injection makes this go red.
        $stored = $DB->get_record('local_coursedynamicrules_action', ['id' => $owner->get_id()], '*', MUST_EXIST);
        $editing = new enableactivity_action($stored, (int) $course->id);
        enableactivity_form::mock_submit([
            'coursemodules' => [(int) $gated->cmid, (int) $free->cmid],
            'courseid' => (int) $course->id,
            'ruleid' => $ruleid,
            'type' => 'enableactivity',
        ]);
        $editing->build_editform(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => (int) $course->id, 'ruleid' => $ruleid, 'type' => 'enableactivity']
        );
        $this->assertNotNull(
            $editing->get_data(),
            'An action editing its own selection must not be refused its own activity.'
        );
    }
    /**
     * DOCUMENTED DEFECT: a live action's gate becomes invisible to the shared-activity refusal as
     * soon as anybody saves the activity's settings form, because core rebuilds the availability
     * tree from scratch and drops any key its own condition plugin does not write.
     *
     * This test asserts the CURRENT, defective behaviour so the hole is codified instead of
     * assumed. Whoever closes it will see this test go red and must state the new contract here.
     * The correct behaviour is that the pair cannot be created; today it can, and the first
     * action's students lose the activity the moment the second one is saved.
     *
     * Why the marker cannot survive, in core:
     *  - availability/yui/src/form/js/form.js:1023 - Item.getValue() builds the node as
     *    {'type': pluginType} and lets only the plugin add its own keys.
     *  - availability/condition/user/yui/src/form/js/form.js:50 - fillValue() writes 'userids' and
     *    nothing else, so 'source' is not carried over.
     *  - availability/yui/src/form/js/form.js:119 - update() runs on initialisation, so merely
     *    opening the module settings form rewrites the hidden field.
     *
     * The reach is therefore ORDINARY USE, not only sites upgraded from before the marker existed:
     * changing a due date on a managed activity is enough.
     *
     * @covers ::modules_gated_by_another_action
     */
    public function test_a_gate_whose_marker_core_stripped_is_invisible_to_the_refusal(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; the action requires it to gate anything.');
        }

        $course = $this->getDataGenerator()->create_course();
        $gated = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $ruleid = $this->create_rule((int) $course->id);
        $record = (object) [
            'id' => null,
            'ruleid' => $ruleid,
            'actiontype' => 'enableactivity',
            'params' => json_encode([]),
        ];
        $owner = new enableactivity_action($record, (int) $course->id);
        $owner->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$gated->cmid],
        ]);
        $ownerid = (int) $owner->get_id();

        // Preconditions, asserted rather than assumed: the marker is written and the refusal works.
        $this->assertSame(
            [(int) $gated->cmid],
            enableactivity_action::modules_gated_by_another_action(
                [(int) $gated->cmid],
                (int) $course->id,
                $ownerid + 1000
            ),
            'Precondition: a marked gate belonging to another action must be reported as a clash.'
        );
        $before = json_decode($DB->get_field('course_modules', 'availability', ['id' => $gated->cmid]));
        $this->assertStringContainsString(
            'local_coursedynamicrules:',
            json_encode($before),
            'Precondition: the action must have written its ownership marker.'
        );

        // What core does when the activity's settings form is saved: the user node is rebuilt with
        // only the keys availability_user writes. Same userids, no marker. Nothing else is touched.
        $stripped = [];
        foreach ($before->c as $node) {
            $stripped[] = $node->type === 'user'
                ? (object) ['type' => 'user', 'userids' => $node->userids ?? []]
                : $node;
        }
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json($stripped, tree::OP_AND, false)),
            ['id' => $gated->cmid]
        );
        rebuild_course_cache((int) $course->id, true);

        // The defect: the gate is still there, still empty until its rule runs, and now invisible.
        $this->assertSame(
            [],
            enableactivity_action::modules_gated_by_another_action(
                [(int) $gated->cmid],
                (int) $course->id,
                $ownerid + 1000
            ),
            'DEFECT: a gate that lost its marker is not reported, so a second action can be saved '
            . 'onto the same activity and close it for the first action\'s students.'
        );

        // And the owner cannot recognise its own gate either, which is what leaves a deleted
        // user\'s id behind: the cleanup looks for a marker that is gone.
        $this->assertSame(
            [],
            enableactivity_action::modules_gated_by_another_action([(int) $gated->cmid], (int) $course->id, $ownerid),
            'DEFECT: the owning action no longer recognises its own gate.'
        );
    }
    /**
     * An activity whose deletion is already running counts as gone in the description, the way it
     * already does everywhere else in the plugin: the four activity conditions treat it as absent
     * (complete_activity_condition.php:155 and its three siblings) and this action's own can_act()
     * excludes it from the query that decides whether the rule may be activated. Only the
     * description still named it, so a rule that could no longer be activated described its target
     * as if nothing had happened for as long as the recycle bin took to finish.
     *
     * @covers ::get_description
     */
    public function test_an_action_whose_activity_is_being_deleted_says_so(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule((int) $course->id);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $action = new enableactivity_action(
            (object) [
                'ruleid' => $ruleid,
                'actiontype' => 'enableactivity',
                'params' => json_encode(['coursemodules' => [(object) ['id' => $page->cmid]]]),
            ],
            (int) $course->id
        );

        $this->assertStringContainsString(
            $page->name,
            $action->get_description(),
            'Precondition: while the activity is healthy the action names it.'
        );

        // Start the deletion the way the interface does, with the recycle bin on: the module stays
        // in the course flagged as being deleted until cron finishes.
        course_delete_module((int) $page->cmid, true);
        $this->assertEquals(
            1,
            $DB->get_field('course_modules', 'deletioninprogress', ['id' => $page->cmid]),
            'Precondition: the deletion must be in progress, not finished.'
        );
        rebuild_course_cache((int) $course->id, true);

        $this->assertSame(
            get_string('componenttargetmissing', 'local_coursedynamicrules'),
            $action->get_description(),
            'An activity being deleted must read as gone, as it already does for can_act().'
        );
    }

    /**
     * And it must not swallow the healthy ones: an action with one activity being deleted and one
     * intact still names the intact one, so the filter cannot pass by warning about everything.
     *
     * @covers ::get_description
     */
    public function test_an_action_keeps_naming_the_activities_that_remain(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule((int) $course->id);
        $doomed = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $healthy = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $action = new enableactivity_action(
            (object) [
                'ruleid' => $ruleid,
                'actiontype' => 'enableactivity',
                'params' => json_encode(['coursemodules' => [
                    (object) ['id' => $doomed->cmid],
                    (object) ['id' => $healthy->cmid],
                ]]),
            ],
            (int) $course->id
        );

        course_delete_module((int) $doomed->cmid, true);
        rebuild_course_cache((int) $course->id, true);

        $description = $action->get_description();
        $this->assertStringContainsString($healthy->name, $description, 'The intact activity is still named.');
        $this->assertStringNotContainsString($doomed->name, $description, 'The one being deleted is not.');
    }
}
