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

use core_privacy\local\request\approved_contextlist;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\privacy\provider;

/**
 * The reconciliation never claims a teacher's restriction, and only resolves what it may.
 *
 * Only a node the restore deduced (adopted) for the pending action, in the shape the action writes,
 * and alone of its kind on the module, may be promoted to written; an unmarked node never is, and an
 * inactive rule is left alone (internal security review, 2026-11-20).
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\restore_reconciliation
 * @covers     \local_coursedynamicrules\local\restore_note
 * @covers     \local_coursedynamicrules\local\gate_fingerprint
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_reconciliation_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A teacher's hidden user restriction on an AND root, with an inactive rule, is never claimed.
     *
     * @return void
     */
    public function test_a_teachers_restriction_is_not_claimed_for_an_inactive_rule(): void {
        global $DB;
        [$course, $cmid, $s1, $s2] = $this->course_with_action(0);
        $teacher = ['op' => '&', 'c' => [['type' => 'user', 'userids' => [(int) $s1->id, (int) $s2->id]]], 'showc' => [false]];
        $DB->set_field('course_modules', 'availability', json_encode($teacher), ['id' => $cmid]);

        $this->assertSame(0, restore_reconciliation::run((int) $course->id, null));

        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $this->assertSame([], enableactivity_action::owned_user_nodes($root), 'The teacher\'s restriction was claimed.');

        $context = \context_module::instance($cmid);
        provider::delete_data_for_user(new approved_contextlist($s1, 'local_coursedynamicrules', [$context->id]));
        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $this->assertSame(
            [(int) $s1->id, (int) $s2->id],
            owned_gate_eraser::userids_of($root->c[0]),
            'An erasure for this plugin edited the teacher\'s restriction.'
        );
    }

    /**
     * An unmarked node is never promoted, even for an active rule.
     *
     * @return void
     */
    public function test_an_unmarked_node_is_never_promoted(): void {
        global $DB;
        [$course, $cmid, $s1] = $this->course_with_action(1);
        $teacher = ['op' => '&', 'c' => [['type' => 'user', 'userids' => [(int) $s1->id]]], 'showc' => [false]];
        $DB->set_field('course_modules', 'availability', json_encode($teacher), ['id' => $cmid]);

        $this->assertSame(0, restore_reconciliation::run((int) $course->id, null));
        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $this->assertSame([], enableactivity_action::owned_user_nodes($root));
    }

    /**
     * A deduced node nested under an OR does not hand the attribution to a teacher's node in the right shape.
     *
     * @return void
     */
    public function test_a_teachers_shaped_node_does_not_win_over_a_nested_deduced_node(): void {
        global $DB;
        [$course, $cmid, $s1, $s2, $actionid] = $this->course_with_action(1);
        $nested = (object) ['op' => '&', 'c' => [
            (object) ['op' => '|', 'c' => [(object) ['type' => 'user', 'userids' => [(int) $s1->id]]], 'show' => false],
        ], 'showc' => [false]];
        $adopted = json_decode(enableactivity_action::adopt_stripped_marker(json_encode($nested), $actionid));
        $adopted->c[] = (object) ['type' => 'user', 'userids' => [(int) $s2->id]];
        $adopted->showc[] = false;
        $DB->set_field('course_modules', 'availability', json_encode($adopted), ['id' => $cmid]);

        $this->assertSame(0, restore_reconciliation::run((int) $course->id, null));

        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $this->assertSame([], enableactivity_action::owned_user_nodes($root), 'A node was promoted.');
        $this->assertNull(enableactivity_action::action_id_of($root->c[1]), 'The teacher\'s node was marked.');
        $this->assertTrue(enableactivity_action::is_adopted($root->c[0]->c[0]), 'The deduced node lost its flag.');
    }

    /**
     * A deduced node listing someone the rule cannot act for (not an active student of the course) is not promoted.
     *
     * @return void
     */
    public function test_a_deduced_node_listing_someone_outside_the_course_is_not_promoted(): void {
        global $DB;
        [$course, $cmid, $s1, , $actionid] = $this->course_with_action(1);
        $outsider = $this->getDataGenerator()->create_user();
        $tree = ['op' => '&', 'c' => [['type' => 'user', 'userids' => [(int) $s1->id, (int) $outsider->id]]], 'showc' => [false]];
        $adopted = enableactivity_action::adopt_stripped_marker(json_encode($tree), $actionid);
        $DB->set_field('course_modules', 'availability', $adopted, ['id' => $cmid]);

        $this->assertSame(0, restore_reconciliation::run((int) $course->id, null));

        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $this->assertSame([], enableactivity_action::owned_user_nodes($root), 'A node listing an outsider was promoted.');
        $this->assertTrue(
            $DB->record_exists(restore_note::TABLE, ['cmid' => $cmid, 'userid' => $outsider->id, 'reason' => 'ambiguous']),
            'The unattributed restriction left no note for the user it lists.'
        );
    }

    /**
     * The single deduced node of the pending action, in the action's shape, is promoted, and only its
     * inferred notes go: an ambiguous note of the module stays.
     *
     * @return void
     */
    public function test_resolving_a_module_removes_only_the_inferred_notes_of_its_action(): void {
        global $DB;
        [$course, $cmid, $s1, $s2, $actionid] = $this->course_with_action(1);
        $tree = ['op' => '&', 'c' => [['type' => 'user', 'userids' => [(int) $s1->id]]], 'showc' => [false]];
        $adopted = enableactivity_action::adopt_stripped_marker(json_encode($tree), $actionid);
        $DB->set_field('course_modules', 'availability', $adopted, ['id' => $cmid]);
        restore_note::record((int) $course->id, $cmid, $actionid, [(int) $s1->id], restore_note::REASON_INFERRED);
        restore_note::record((int) $course->id, $cmid, null, [(int) $s2->id], restore_note::REASON_AMBIGUOUS);

        $this->assertSame(1, restore_reconciliation::run((int) $course->id, null));

        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $owned = enableactivity_action::owned_user_nodes($root);
        $this->assertCount(1, $owned);
        $this->assertSame($actionid, enableactivity_action::action_id_of($owned[0]));
        $this->assertFalse($DB->record_exists(restore_note::TABLE, ['cmid' => $cmid, 'reason' => 'inferred']));
        $this->assertTrue(
            $DB->record_exists(restore_note::TABLE, ['cmid' => $cmid, 'userid' => $s2->id, 'reason' => 'ambiguous']),
            'The reconciliation deleted an ambiguous note it did not resolve.'
        );
    }

    /**
     * Recording the notes of many users costs a bounded number of queries.
     *
     * @return void
     */
    public function test_recording_many_notes_takes_a_bounded_number_of_queries(): void {
        global $DB;
        $userids = range(1000, 1099);
        $before = $DB->perf_get_queries();
        restore_note::record(1, 2, 3, $userids, restore_note::REASON_AMBIGUOUS);
        $this->assertLessThanOrEqual(5, $DB->perf_get_queries() - $before);
        $this->assertSame(100, $DB->count_records(restore_note::TABLE, ['cmid' => 2]));

        restore_note::record(1, 2, 3, $userids, restore_note::REASON_AMBIGUOUS);
        $this->assertSame(100, $DB->count_records(restore_note::TABLE, ['cmid' => 2]), 'Notes were recorded twice.');
    }

    /**
     * A tree larger than the cap yields no positions, so no fingerprint of either version can match in it.
     *
     * @return void
     */
    public function test_a_tree_beyond_the_cap_matches_nothing(): void {
        $node = (object) ['type' => 'user', 'userids' => [5]];
        $children = [$node];
        for ($i = 0; $i < gate_fingerprint::MAX_TREE_NODES; $i++) {
            $children[] = (object) ['type' => 'completion', 'cm' => $i + 1, 'e' => 1];
        }
        $root = (object) ['op' => '&', 'c' => $children, 'showc' => array_fill(0, count($children), false)];

        $this->assertSame([], gate_fingerprint::positions($root));
        $this->assertSame([], gate_fingerprint::matching_nodes($root, 7, 7, gate_fingerprint::of(7, [5])));
    }

    /**
     * A course with one enable-activity action listing one page.
     *
     * @param int $active Whether the rule is active.
     * @return array [course, cmid, student 1, student 2, action id]
     */
    private function course_with_action(int $active): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $s1 = $generator->create_and_enrol($course, 'student');
        $s2 = $generator->create_and_enrol($course, 'student');
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Reconciled rule',
            'active' => $active,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $actionid = (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => 'enableactivity',
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [['id' => (int) $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]]]),
        ]);

        return [$course, (int) $page->cmid, $s1, $s2, $actionid];
    }
}
