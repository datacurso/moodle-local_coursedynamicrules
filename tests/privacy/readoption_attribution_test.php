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

use core_availability\tree;
use core_privacy\local\request\approved_contextlist;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

/**
 * What the restore's re-adoption hands the privacy provider, and whether the provider may believe it.
 *
 * The provider's whole contract rests on one predicate: a restriction carrying this plugin's marker
 * was written by this plugin, and an unmarked one belongs to whoever wrote it. data_provider_test
 * pins the second half directly - a teacher's own user restriction is never claimed.
 *
 * adopt_stripped_marker() can put the marker on a node nobody proved we wrote. It claims the single
 * unmarked user node of the tree, looking at neither who wrote it nor which ids it holds, and the
 * restore calls it for every course module listed in a restored action's params
 * (restore_local_coursedynamicrules_plugin::readopt_stripped_markers). A teacher who re-saved that
 * activity's access restrictions through the core UI destroyed our marker in the process - that is
 * documented at MARKER_KEY and is the ordinary case, not an exotic one - so what the tree carries
 * afterwards can be the teacher's own restriction and nothing else.
 *
 * These tests do not ask whether the stamp is written; they ask what the stamp COSTS, which is the
 * claim the delivery document and the provider's own docblock make: that an approved erasure for
 * this component never destroys a restriction the plugin did not write.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\action\enableactivity\enableactivity_action::adopt_stripped_marker
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class readoption_attribution_test extends \core_privacy\tests\provider_testcase {
    /**
     * An enable-activity action that lists the given module, with nothing granted through it.
     *
     * The action is saved rather than executed on purpose: what is being reproduced is an activity
     * the operator chose for a gate whose node no longer exists, which is what a teacher re-saving
     * that activity's restrictions leaves behind.
     *
     * @param \stdClass $course The course the rule belongs to.
     * @param int $cmid The module the action records as its own.
     * @return int The action id, which is what the restore hands the re-adoption.
     */
    private function action_listing(\stdClass $course, int $cmid): int {
        return (int) $this->action_for($course, $cmid)->get_id();
    }

    /**
     * Strip this plugin's markers from a module's tree, the way core's own re-encode does.
     *
     * availability_user::save() emits {type, userids} and nothing else, so any property this plugin
     * added is gone whenever core rebuilds the tree - on a restore, on a course reset that shifts
     * dates, on a teacher saving the module form. This reproduces that end state directly.
     *
     * @param int $cmid The module.
     * @return void
     */
    private function strip_markers(int $cmid): void {
        global $DB;
        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $walk = function ($node) use (&$walk) {
            foreach ((array) ($node->c ?? []) as $child) {
                $walk($child);
            }
            if (($node->type ?? null) === 'user') {
                unset($node->source, $node->sourceadopted);
            }
        };
        $walk($root);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cmid]);
    }

    /**
     * How many user-restriction nodes a module's tree holds, at any depth.
     *
     * @param int $cmid The module.
     * @return int
     */
    private function user_node_count(int $cmid): int {
        global $DB;
        $count = 0;
        $walk = function ($node) use (&$walk, &$count) {
            foreach ((array) ($node->c ?? []) as $child) {
                $walk($child);
            }
            if (($node->type ?? null) === 'user') {
                $count++;
            }
        };
        $walk(json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid])));
        return $count;
    }

    /**
     * The action behind {@see action_listing()}, for the tests that need to drive it.
     *
     * @param \stdClass $course The course the rule belongs to.
     * @param int $cmid The module the action records as its own.
     * @return enableactivity_action
     */
    private function action_for(\stdClass $course, int $cmid): enableactivity_action {
        global $DB;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Enable rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, (int) $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$cmid],
        ]);
        return $action;
    }

    /**
     * Put an UNMARKED user restriction on a module: what a teacher's own "Restrict access" writes.
     *
     * Written last in every test here, so the tree holds the teacher's restriction and nothing else
     * whatever the action did on save - which is the state a re-save through the core UI produces.
     *
     * @param int $cmid The module.
     * @param int[] $userids The users the teacher listed.
     * @return void
     */
    private function teacher_restriction(int $cmid, array $userids): void {
        global $DB;
        $node = (object) ['type' => 'user', 'userids' => array_values($userids)];
        $tree = tree::get_root_json([$node], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * A teacher's own user restriction, wrapped in one or more groups, innermost operator first.
     *
     * A teacher who regroups an activity's restrictions through the core UI produces exactly this,
     * and collect_user_nodes() descends into every child without looking at the operator above it -
     * so depth does not protect a node from being claimed. The negating case matters beyond the
     * attribution itself: under a "must not match" group the list names the people kept OUT, so
     * removing an id from it ADMITS that person rather than excluding them.
     *
     * @param int $cmid The module.
     * @param int[] $userids The users the teacher listed.
     * @param string[] $ops The operators to nest the restriction under, innermost first.
     * @return void
     */
    private function teacher_restriction_nested(int $cmid, array $userids, array $ops): void {
        global $DB;
        $node = (object) ['type' => 'user', 'userids' => array_values($userids)];
        foreach ($ops as $op) {
            $node = (object) ['op' => $op, 'c' => [$node], 'showc' => [false]];
        }
        $tree = tree::get_root_json([$node], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * Two unmarked user restrictions on one module: the shape the re-adoption must refuse.
     *
     * With more than one candidate there is no single node to claim, and guessing between them
     * would be worse than doing nothing. The refusal is deliberate and documented at
     * adopt_stripped_marker(); it is pinned here so a later change cannot quietly start claiming.
     *
     * @param int $cmid The module.
     * @param int[] $first The users the first restriction lists.
     * @param int[] $second The users the second restriction lists.
     * @return void
     */
    private function two_unmarked_restrictions(int $cmid, array $first, array $second): void {
        global $DB;
        $nodes = [
            (object) ['type' => 'user', 'userids' => array_values($first)],
            (object) ['type' => 'user', 'userids' => array_values($second)],
        ];
        $tree = tree::get_root_json($nodes, tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * Run the re-adoption exactly as the restore's after-restore pass runs it.
     *
     * The restore loop reads the module's availability, calls adopt_stripped_marker() with the
     * restored action's id and writes back whatever it returns; this reproduces that, so the
     * function under test is the real one rather than a paraphrase of it.
     *
     * @param int $cmid The module the restored action lists.
     * @param int $actionid The restored action.
     * @return bool Whether the tree was rewritten.
     */
    private function readopt(int $cmid, int $actionid): bool {
        global $DB;
        $before = (string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        $after = enableactivity_action::adopt_stripped_marker($before, $actionid);
        if ($after === null || $after === $before) {
            return false;
        }
        $DB->set_field('course_modules', 'availability', $after, ['id' => $cmid]);
        return true;
    }

    /**
     * The user ids a module's availability tree holds, wherever they sit in it.
     *
     * @param int $cmid The module.
     * @return int[] Every id the tree names, sorted.
     */
    private function ids_in(int $cmid): array {
        global $DB;
        $raw = (string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            foreach ((array) ($node->c ?? []) as $child) {
                $walk($child);
            }
            if (($node->type ?? null) === 'user') {
                foreach ((array) ($node->userids ?? []) as $id) {
                    $found[] = (int) $id;
                }
                if (isset($node->userid)) {
                    $found[] = (int) $node->userid;
                }
            }
        };
        $walk(json_decode($raw));
        sort($found);
        return $found;
    }

    /**
     * An approved erasure request for one user, over every context the provider itself declared.
     *
     * @param int $userid The data subject.
     * @return approved_contextlist What tool_dataprivacy hands the provider once the request is approved.
     */
    private function approved_for(int $userid): approved_contextlist {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $contextlist = provider::get_contexts_for_userid($userid);
        return new approved_contextlist($user, 'local_coursedynamicrules', $contextlist->get_contextids());
    }

    /**
     * A restriction the plugin never wrote stays unclaimed after a restore re-adopts markers.
     *
     * @return void
     */
    public function test_the_readoption_does_not_claim_a_restriction_the_plugin_never_wrote(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $actionid = $this->action_listing($course, (int) $module->cmid);
        $this->teacher_restriction((int) $module->cmid, [(int) $theirs->id]);

        // Precondition, so a failure below cannot be read as the provider being broken already:
        // before the restore runs, the plugin correctly wants nothing to do with this restriction.
        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $theirs->id)->get_contextids(),
            'Precondition: an unmarked restriction must be unclaimed, or this test proves nothing.'
        );

        $this->readopt((int) $module->cmid, $actionid);

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $theirs->id)->get_contextids(),
            'The re-adoption stamped a restriction the plugin never wrote, and the provider now '
                . 'reports a context it has no right to clean.'
        );
    }

    /**
     * An approved erasure leaves a teacher's own restriction exactly as the teacher wrote it.
     *
     * This is the consequence the stamp buys, and the reason the finding is not cosmetic: the
     * erasure is approved for THIS component alone, so every id it removes from somebody else's
     * restriction is configuration destroyed inside a request that never covered it.
     *
     * @return void
     */
    public function test_an_approved_erasure_leaves_a_teachers_restriction_as_the_teacher_wrote_it(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $subject = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $actionid = $this->action_listing($course, (int) $module->cmid);
        $teacherwrote = [(int) $subject->id, (int) $bystander->id];
        sort($teacherwrote);
        $this->teacher_restriction((int) $module->cmid, $teacherwrote);

        $this->readopt((int) $module->cmid, $actionid);

        provider::delete_data_for_user($this->approved_for((int) $subject->id));

        $this->assertSame(
            $teacherwrote,
            $this->ids_in((int) $module->cmid),
            'An erasure approved for this plugin removed an id from a restriction a teacher wrote, '
                . 'which the provider promises never to touch.'
        );
    }

    /**
     * Depth does not make a restriction the plugin's, and a negating group makes it worse.
     *
     * The same claim as the root-level case, on the shape that compounds with a limitation the
     * provider already declares: inside a "must not match" group the list names the people kept
     * out, so an erasure there does not withdraw access - it GRANTS it, to an activity a teacher
     * had excluded that person from.
     *
     * @return void
     */
    public function test_the_readoption_does_not_claim_a_teachers_restriction_nested_under_a_group(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $subject = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $actionid = $this->action_listing($course, (int) $module->cmid);
        $excluded = [(int) $subject->id, (int) $bystander->id];
        sort($excluded);
        $this->teacher_restriction_nested((int) $module->cmid, $excluded, ['!&']);

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $subject->id)->get_contextids(),
            'Precondition: a nested unmarked restriction must be unclaimed, or this test proves nothing.'
        );

        $this->readopt((int) $module->cmid, $actionid);

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $subject->id)->get_contextids(),
            'The re-adoption reached into a nested group and stamped a restriction the plugin never '
                . 'wrote: descending without looking at the operator above the node.'
        );

        provider::delete_data_for_user($this->approved_for((int) $subject->id));

        $this->assertSame(
            $excluded,
            $this->ids_in((int) $module->cmid),
            'An erasure approved for this plugin emptied a name out of a negated restriction, which '
                . 'admits that person to an activity a teacher had excluded them from.'
        );
    }

    /**
     * Two unmarked restrictions leave nothing to claim, and the re-adoption writes nothing at all.
     *
     * This one is green today and is here to STAY green: the refusal on ambiguity is the only
     * reason the damage above is bounded, and a repair that made the claim narrower could just as
     * easily make this one wider. Asserted on the raw column rather than on the provider, because
     * what has to hold is that nothing was written - not merely that nothing is reported.
     *
     * @return void
     */
    public function test_the_readoption_writes_nothing_when_two_unmarked_nodes_make_it_ambiguous(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $actionid = $this->action_listing($course, (int) $module->cmid);
        $this->two_unmarked_restrictions((int) $module->cmid, [(int) $first->id], [(int) $second->id]);
        $before = (string) $DB->get_field('course_modules', 'availability', ['id' => $module->cmid]);

        $this->assertFalse(
            $this->readopt((int) $module->cmid, $actionid),
            'With two candidates there is nothing to claim, so the re-adoption must not rewrite the tree.'
        );

        $this->assertSame(
            $before,
            (string) $DB->get_field('course_modules', 'availability', ['id' => $module->cmid]),
            'The stored tree changed although the re-adoption had no node it could prove was ours.'
        );

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $first->id)->get_contextids(),
            'Nothing was claimed, so neither restriction may appear as this plugin\'s data.'
        );
    }

    /**
     * The engine keeps treating an adopted gate as the action's own, which is why it is still marked.
     *
     * This is the reason the repair withholds belief instead of withholding the marker. Nothing in
     * the engine has a fallback when it comes to CREATING a gate: apply_availability() matches on the
     * marker alone, so an unmarked gate reads as no gate at all and a second one - empty, and
     * therefore satisfied by nobody - gets appended beside it, hiding the activity from the whole
     * course including the students the first gate had let in. Withholding the marker would have
     * traded a privacy defect for an availability one.
     *
     * @return void
     */
    public function test_an_adopted_gate_is_still_the_actions_own_gate_for_the_engine(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $action = $this->action_for($course, (int) $module->cmid);
        $this->assertSame(
            1,
            $this->user_node_count((int) $module->cmid),
            'Precondition: saving an active rule\'s action writes exactly one gate.'
        );

        // Core re-encodes the tree and the marker goes with it; the restore deduces it back.
        $this->strip_markers((int) $module->cmid);
        $this->assertTrue(
            $this->readopt((int) $module->cmid, (int) $action->get_id()),
            'Precondition: the re-adoption must act, or what follows proves nothing.'
        );

        // The operator switches the rule off and on again - every gate is re-applied.
        $action->on_rule_activated();

        $this->assertSame(
            1,
            $this->user_node_count((int) $module->cmid),
            'A second, empty gate was appended beside the adopted one: the engine no longer '
                . 'recognises its own restriction, and an empty gate hides the activity from everybody.'
        );

        $action->execute((object) ['courseid' => $course->id, 'userid' => (int) $student->id]);

        $this->assertSame(
            [(int) $student->id],
            $this->ids_in((int) $module->cmid),
            'The action could not grant through the adopted gate, so the rule no longer opens the '
                . 'activity for the students that meet it.'
        );
    }

    /**
     * Writing a node the plugin's own clears any earlier deduction about it.
     *
     * The deduction flag says "nobody proved this is ours". A stamp written by this plugin is that
     * proof, so the two cannot coexist: leaving the flag would hide from the privacy provider a
     * node the plugin itself wrote - the exact failure this whole repair exists to prevent, with
     * the sign reversed.
     *
     * @return void
     */
    public function test_stamping_a_node_clears_an_earlier_deduction(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $actionid = $this->action_listing($course, (int) $module->cmid);
        $this->teacher_restriction((int) $module->cmid, [(int) $student->id]);
        $this->readopt((int) $module->cmid, $actionid);

        global $DB;
        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $module->cmid]));
        $node = $root->c[0];
        $this->assertTrue(
            !empty($node->sourceadopted),
            'Precondition: the node must be flagged as deduced before the stamp is re-applied.'
        );

        enableactivity_action::mark_node($node, $actionid);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $module->cmid]);

        $this->assertSame(
            [(int) \context_module::instance($module->cmid)->id],
            array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids()),
            'The plugin stamped this node itself, so the provider must account for it: a stale '
                . 'deduction flag outlived the proof that replaced it.'
        );
    }
}
