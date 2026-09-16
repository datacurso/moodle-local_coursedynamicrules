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
use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\condition\complete_activity\complete_activity_condition;
use local_coursedynamicrules\task\rule_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * When a granted user is deleted from the site, their id must not linger inside the plugin's
 * access restriction on the managed activities.
 *
 * Materialises the privacy step of MDL-E2E-011. The "enable activity" action writes the student's
 * id into each managed module's user restriction. Core's availability_user declares itself a privacy
 * null_provider and nothing in core reacts to a user deletion on its behalf, so without the plugin's
 * own cleanup the id stays behind for good and the restriction keeps showing the deleted person's
 * name. The cleanup must reach ONLY the nodes the plugin owns - its marked node, or the sole unmarked
 * node of a module it manages, the same rule execute() applies to find its own node - it must run on
 * a sealed rule too, since a rule that has run against students is normally sealed, and a rule task
 * queued before the deletion must not undo it.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\action\enableactivity\enableactivity_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class granted_user_deletion_test extends \advanced_testcase {
    /**
     * Insert a rule row in the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Enable rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Put a single UNMARKED user restriction on a module, holding the given user ids: what a teacher's
     * own "Restrict access" node looks like, and what the plugin's node looked like before the marker.
     *
     * @param int $cmid Course module id.
     * @param int[] $userids User ids the node lists.
     * @return void
     */
    private function set_unmarked_user_restriction(int $cmid, array $userids): void {
        global $DB;
        $node = (object) ['type' => 'user', 'userids' => array_values($userids)];
        $tree = tree::get_root_json([$node], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * The decoded availability tree of a module, read back from the database.
     *
     * @param int $cmid Course module id.
     * @return \stdClass
     */
    private function availability_of(int $cmid): \stdClass {
        global $DB;
        $raw = $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        $this->assertNotEmpty($raw, 'The module must still carry an availability tree.');
        return json_decode($raw);
    }

    /**
     * The user ids a restriction node lists, as integers: execute() stores whatever type the rule
     * engine hands it, so a stored list can mix strings and integers.
     *
     * @param \stdClass $node A 'user' condition node.
     * @return int[]
     */
    private function userids_of(\stdClass $node): array {
        return array_map('intval', $node->userids);
    }

    /**
     * Split a tree's root-level user nodes into the plugin's own (marked) ones and the rest. The
     * marker is the 'source' property the action writes on the nodes it creates.
     *
     * @param \stdClass $tree Decoded availability tree.
     * @return array{0: \stdClass[], 1: \stdClass[]} [marked nodes, unmarked nodes].
     */
    private function split_user_nodes(\stdClass $tree): array {
        $marked = [];
        $unmarked = [];
        foreach ($tree->c as $node) {
            if (($node->type ?? null) !== 'user') {
                continue;
            }
            if (isset($node->source)) {
                $marked[] = $node;
            } else {
                $unmarked[] = $node;
            }
        }
        return [$marked, $unmarked];
    }

    /**
     * Delete a user exactly as the site does, so the real deletion path runs end to end.
     *
     * @param int $userid User id.
     * @return void
     */
    private function delete_site_user(int $userid): void {
        global $DB;
        delete_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
    }

    /**
     * The site's own user deletion also reaches a gate written in the legacy singular shape.
     *
     * availability_user has always accepted `{"type":"user","userid":42}` and still does - its
     * constructor pushes that key onto the list it evaluates. A gate of ours in that shape restricts
     * the activity to that student exactly as a list would, so leaving the id there after the account
     * is gone is the orphan reference this cleanup exists to prevent, in a shape nobody checked.
     *
     * The assertion asks the question CORE asks - which students does this restriction name - rather
     * than reading the plural key, which is the very key the defect hides behind.
     *
     * @covers ::revoke_user
     * @covers \local_coursedynamicrules\observer\user_deleted::observe
     */
    public function test_deleting_a_granted_user_scrubs_a_legacy_single_userid_node(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $keeper = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $managed = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $ruleid = $this->create_rule($course->id);
        $record = (object) [
            'id' => null,
            'ruleid' => $ruleid,
            'actiontype' => 'enableactivity',
            'params' => json_encode([]),
        ];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$managed->cmid],
        ]);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);

        // Rewrite our own gate so the grantee is named by the legacy key and the keeper by the list.
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $managed->cmid]));
        $node = enableactivity_action::owned_user_nodes($tree)[0];
        $node->userids = [(int) $keeper->id];
        $node->userid = (int) $grantee->id;
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $managed->cmid]);

        $this->delete_site_user($grantee->id);

        [$marked] = $this->split_user_nodes($this->availability_of($managed->cmid));
        $this->assertCount(1, $marked, 'The gate must survive: a tree without it restricts nobody.');
        $names = array_map('intval', (array) ($marked[0]->userids ?? []));
        if (isset($marked[0]->userid)) {
            $names[] = (int) $marked[0]->userid;
        }
        $this->assertSame(
            [(int) $keeper->id],
            $names,
            'Core still reads the deleted user out of this gate: the legacy key was left behind.'
        );
        $this->assertDebuggingNotCalled();
    }

    /**
     * MDL-E2E-011: deleting a granted user removes their id from a legacy, unmarked node of a module
     * the action manages, and changes nothing else in that node.
     *
     * The node was written before the marker existed; execute() still adopts it because it is the
     * sole unmarked user node of a module listed in the action's params, and the cleanup must apply
     * the same rule. The node itself stays, so the module remains gated, and a second grantee keeps
     * the access they were given.
     *
     * @covers ::execute
     * @covers ::revoke_user
     * @covers \local_coursedynamicrules\observer\user_deleted::observe
     */
    public function test_deleting_a_granted_user_scrubs_their_id_from_a_legacy_unmarked_node(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->set_unmarked_user_restriction($page->cmid, []);

        // The action row was saved before the marker existed: its params list the module, and the
        // node on the module carries no marker. It is a stored row, as every action that runs is.
        $actionid = $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $this->create_rule($course->id),
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [(object) ['id' => $page->cmid]]]),
        ]);
        $stored = $DB->get_record('local_coursedynamicrules_action', ['id' => $actionid], '*', MUST_EXIST);
        $action = new enableactivity_action($stored, $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $bystander->id]);

        // Sanity: both grants landed, or this test proves nothing.
        $this->assertEqualsCanonicalizing(
            [(int) $grantee->id, (int) $bystander->id],
            $this->userids_of($this->availability_of($page->cmid)->c[0]),
            'Sanity: execute() must have granted access to both students.'
        );

        // The site deletes one of them.
        $this->delete_site_user($grantee->id);

        $after = $this->availability_of($page->cmid);
        $this->assertCount(1, $after->c, 'The node itself stays: the module is still gated for everyone else.');
        $this->assertSame('user', $after->c[0]->type);
        $this->assertSame(
            [(int) $bystander->id],
            $this->userids_of($after->c[0]),
            'Only the deleted user leaves the restriction; the other grantee keeps their access.'
        );
        $this->assertDebuggingNotCalled();
    }

    /**
     * MDL-E2E-011: on the production path - an action saved through save_action(), so its node is
     * marked, on a rule that has run and is therefore sealed - deletion scrubs the plugin's OWN node
     * and nothing else: a teacher's user restriction on the same module keeps the id, and so does a
     * restriction on a module the plugin does not manage. Those are not the plugin's to edit.
     *
     * The seal matters: the rule lock refuses edits to an activated rule, and a cleanup that went
     * through that gate would silently do nothing on the rules that matter most.
     *
     * @covers ::save_action
     * @covers ::execute
     * @covers ::revoke_user
     * @covers \local_coursedynamicrules\observer\user_deleted::observe
     */
    public function test_deleting_a_granted_user_scrubs_only_the_plugins_own_node_even_on_a_sealed_rule(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $managed = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $unmanaged = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // The teacher already restricts both modules to the grantee by hand.
        $this->set_unmarked_user_restriction($managed->cmid, [$grantee->id]);
        $this->set_unmarked_user_restriction($unmanaged->cmid, [$grantee->id]);

        $ruleid = $this->create_rule($course->id);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$managed->cmid],
        ]);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $bystander->id]);

        // The rule has run against students: it is sealed, as every rule that ever granted access is.
        $DB->set_field('local_coursedynamicrules_rule', 'timeactivated', time(), ['id' => $ruleid]);

        // Sanity: the module now carries the teacher's node AND the plugin's marked node, and the
        // grant landed in the plugin's one.
        [$marked, $unmarked] = $this->split_user_nodes($this->availability_of($managed->cmid));
        $this->assertCount(1, $marked, 'Sanity: save_action() must have added the plugin\'s marked node.');
        $this->assertCount(1, $unmarked, 'Sanity: the teacher\'s node must have survived save_action().');
        $this->assertEqualsCanonicalizing(
            [(int) $grantee->id, (int) $bystander->id],
            $this->userids_of($marked[0]),
            'Sanity: execute() must have granted access in the plugin\'s own node.'
        );

        // The site deletes the grantee.
        $this->delete_site_user($grantee->id);

        [$marked, $unmarked] = $this->split_user_nodes($this->availability_of($managed->cmid));
        $this->assertCount(1, $marked, 'The plugin\'s node survives, marker included.');
        $this->assertSame(
            [(int) $bystander->id],
            $this->userids_of($marked[0]),
            'Only the deleted user leaves the plugin\'s node; the other grantee keeps their access.'
        );
        $this->assertCount(1, $unmarked);
        $this->assertSame(
            [(int) $grantee->id],
            $this->userids_of($unmarked[0]),
            'The teacher\'s own restriction on the same module is not the plugin\'s to edit.'
        );
        $this->assertSame(
            [(int) $grantee->id],
            $this->userids_of($this->availability_of($unmanaged->cmid)->c[0]),
            'A module the plugin does not manage is not touched at all.'
        );
        $this->assertDebuggingNotCalled();
    }

    /**
     * MDL-E2E-011: a managed module that was deleted after the grant must not stop the cleanup of the
     * modules that still exist. The action's params still list the deleted module; the cleanup skips
     * it the way the action's own delete path does.
     *
     * @covers ::save_action
     * @covers ::execute
     * @covers ::revoke_user
     * @covers \local_coursedynamicrules\observer\user_deleted::observe
     */
    public function test_deleting_a_granted_user_survives_a_managed_module_that_no_longer_exists(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $kept = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $doomed = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $ruleid = $this->create_rule($course->id);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            // The doomed module first, so the cleanup meets the gap before it reaches the survivor.
            'coursemodules' => [$doomed->cmid, $kept->cmid],
        ]);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);

        // Sanity: the grant landed on the module that will survive.
        $this->assertContains(
            (int) $grantee->id,
            $this->userids_of($this->availability_of($kept->cmid)->c[0]),
            'Sanity: execute() must have granted access on the kept module.'
        );

        // The teacher deletes one of the managed modules; the action's params still list it.
        course_delete_module($doomed->cmid);

        $this->delete_site_user($grantee->id);

        $this->assertNotContains(
            (int) $grantee->id,
            $this->userids_of($this->availability_of($kept->cmid)->c[0]),
            'The cleanup must reach the surviving module although a sibling module is gone.'
        );
        $this->assertDebuggingNotCalled();
    }

    /**
     * MDL-E2E-011: a managed module whose plugin node lost its marker and sits next to a teacher's own
     * user node is ambiguous - two unmarked user nodes - and the cleanup must not guess. Both nodes stay
     * as they are and the case is reported for manual cleanup, the policy the action's delete path
     * already follows.
     *
     * @covers ::revoke_user
     * @covers \local_coursedynamicrules\observer\user_deleted::observe
     */
    public function test_deleting_a_granted_user_leaves_an_ambiguous_module_alone_and_reports_it(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // Two unmarked user nodes, both listing the grantee: nothing tells which one the plugin wrote.
        $tree = tree::get_root_json([
            (object) ['type' => 'user', 'userids' => [$grantee->id]],
            (object) ['type' => 'user', 'userids' => [$grantee->id]],
        ], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $page->cmid]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $this->create_rule($course->id),
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [(object) ['id' => $page->cmid]]]),
        ]);

        $this->delete_site_user($grantee->id);

        $after = $this->availability_of($page->cmid);
        $this->assertCount(2, $after->c, 'Both nodes stay.');
        $this->assertSame(
            [(int) $grantee->id],
            $this->userids_of($after->c[0]),
            'Neither node is the plugin\'s to edit when it cannot tell which one is its own.'
        );
        $this->assertSame([(int) $grantee->id], $this->userids_of($after->c[1]));
        $this->assertDebuggingCalledCount(1);
    }

    /**
     * MDL-E2E-011: a rule task queued before the deletion must not act for the deleted user once cron
     * drains the queue. The completion record that queued it survives both the unenrolment and the
     * deletion, so without this guard the enable-activity action would write the id straight back into
     * the restriction the cleanup had just scrubbed.
     *
     * @covers \local_coursedynamicrules\task\rule_task::execute
     */
    public function test_a_rule_task_queued_before_the_deletion_does_not_grant_the_deleted_user(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $watched = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $reward = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // An active rule: completing the watched activity opens the reward.
        $ruleid = $this->create_rule($course->id);
        $condition = new complete_activity_condition(
            (object) ['ruleid' => $ruleid, 'conditiontype' => 'complete_activity', 'params' => json_encode([])],
            $course->id
        );
        $condition->save_condition((object) ['ruleid' => $ruleid, 'coursemodule' => $watched->cmid]);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$reward->cmid],
        ]);

        // The student completes the watched activity: the plugin queues its rule task, as in production.
        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('page', $watched->cmid, $course->id, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $queued = $DB->count_records_select(
            'task_adhoc',
            $DB->sql_like('classname', ':classname'),
            ['classname' => '%rule_task']
        );
        $this->assertSame(1, $queued, 'Sanity: the completion must have queued exactly one rule task.');

        // Before cron drains the queue, the site deletes the student.
        $this->delete_site_user($student->id);

        // Cron drains the queue.
        $this->runAdhocTasks(rule_task::class);

        [$marked] = $this->split_user_nodes($this->availability_of($reward->cmid));
        $this->assertCount(1, $marked, 'Sanity: the reward carries the plugin\'s node.');
        $this->assertSame(
            [],
            $this->userids_of($marked[0]),
            'A rule must not open an activity for a user the site has deleted.'
        );
        $this->assertDebuggingNotCalled();
    }
}
