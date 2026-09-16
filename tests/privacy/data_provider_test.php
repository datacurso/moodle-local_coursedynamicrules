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
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

/**
 * The plugin writes user ids into {course_modules}.availability and nobody accounts for them.
 *
 * The enable-activity action grants a student access by adding their id to a restriction node it
 * owns - a node it stamps with its own marker so it can tell it apart from a restriction a teacher
 * added by hand. The column is core's, but core declares nothing about what is inside it:
 * core_availability's provider is a null_provider and availability_user's is too. So those ids are
 * this plugin's to disclose, export and erase, and until this class existed a data subject asking
 * Moodle "what do you hold about me" was told nothing and asking "erase it" left the id in place.
 *
 * Four properties are pinned here, and each one is a way the repair could go wrong rather than a
 * restatement of the API:
 *
 * 1. It finds what it owns. A module gated by the plugin is reported as a context of that user.
 * 2. It claims nothing else. A teacher's own user restriction looks identical apart from the
 *    marker, and reporting it would make an approved erasure destroy a restriction the plugin never
 *    wrote. Refusing to claim it is the difference between a provider and a wrecking ball.
 * 3. Erasure removes the ID and KEEPS the node. The availability tree is AND-combined, so a node
 *    that disappears stops restricting anything: erasing the node would OPEN the activity to the
 *    whole course. That is the opposite of what the request asked for.
 * 4. The course cache is invalidated. The gate students are evaluated against comes from
 *    modinfo, not from the column, so a write nobody invalidates erases the id in the database and
 *    leaves the student's access exactly as it was.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class data_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Insert a rule for a course and return its id.
     *
     * @param int $courseid The course.
     * @return int The rule id.
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
     * Gate a module through the real action and grant it to the given users.
     *
     * Deliberately not a hand-written JSON fixture: what the provider has to recognise is the node
     * the action really writes, marker format included, and a fixture would let the two drift.
     *
     * @param \stdClass $course The course.
     * @param int $cmid The module to gate.
     * @param int[] $userids The users to grant.
     * @return enableactivity_action The action that owns the gate.
     */
    private function gate_module(\stdClass $course, int $cmid, array $userids): enableactivity_action {
        $ruleid = $this->create_rule((int) $course->id);
        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'actiontype' => 'enableactivity', 'params' => json_encode([])];
        $action = new enableactivity_action($record, (int) $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'courseid' => $course->id,
            'coursemodules' => [$cmid],
        ]);
        foreach ($userids as $userid) {
            $action->execute((object) ['courseid' => $course->id, 'userid' => $userid]);
        }
        return $action;
    }

    /**
     * Put an UNMARKED user restriction on a module: what a teacher's own "Restrict access" looks like.
     *
     * @param int $cmid The module.
     * @param int[] $userids The users it lists.
     * @return void
     */
    private function set_unmarked_user_restriction(int $cmid, array $userids): void {
        global $DB;
        $node = (object) ['type' => 'user', 'userids' => array_values($userids)];
        $tree = tree::get_root_json([$node], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * Split a module's root-level user nodes into the plugin's own (marked) ones and the rest.
     *
     * @param int $cmid The module.
     * @return array{0: \stdClass[], 1: \stdClass[]} [marked nodes, unmarked nodes].
     */
    private function user_nodes(int $cmid): array {
        global $DB;
        $raw = $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        $this->assertNotEmpty($raw, 'The module must still carry an availability tree.');
        $marked = [];
        $unmarked = [];
        foreach (json_decode($raw)->c as $node) {
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
     * The user ids a node lists, as integers: the stored list can mix strings and integers.
     *
     * @param \stdClass $node A user restriction node.
     * @return int[]
     */
    private function ids_of(\stdClass $node): array {
        return array_map('intval', (array) $node->userids);
    }

    /**
     * Approve every context the provider reported for a user, as tool_dataprivacy does.
     *
     * @param int $userid The user.
     * @return approved_contextlist The approved list.
     */
    private function approve_all_for(int $userid): approved_contextlist {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $contextlist = provider::get_contexts_for_userid($userid);
        return new approved_contextlist($user, 'local_coursedynamicrules', $contextlist->get_contextids());
    }

    /**
     * A module the plugin gates is one of the granted user's contexts.
     *
     * @return void
     */
    public function test_the_gated_module_is_one_of_the_granted_users_contexts(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $grantee->id]);

        $contextids = provider::get_contexts_for_userid((int) $grantee->id)->get_contextids();

        $this->assertSame(
            [(int) \context_module::instance($module->cmid)->id],
            array_map('intval', $contextids),
            'The module whose gate lists the user is the context the plugin holds data in.'
        );
    }

    /**
     * A restriction the plugin did not write is not reported as the plugin's.
     *
     * The node is identical to the plugin's apart from the marker, so a provider that matched on
     * node type alone would claim it - and an approved erasure would then rewrite a teacher's own
     * restriction. The plugin has no business in it: it neither wrote it nor can prove it did.
     *
     * @return void
     */
    public function test_a_teachers_own_restriction_is_not_claimed_by_the_plugin(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->set_unmarked_user_restriction((int) $module->cmid, [(int) $student->id]);

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $student->id)->get_contextids(),
            'An unmarked restriction belongs to whoever wrote it, and that is not this plugin.'
        );

        $userlist = new userlist(\context_module::instance($module->cmid), 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * The users in a module context are the ones the plugin's own gate lists, and only those.
     *
     * @return void
     */
    public function test_the_users_in_context_are_the_ones_the_plugins_gate_lists(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $granted = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $alsogranted = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teachersonly = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // The teacher's own restriction on the same module lists a third student.
        $this->set_unmarked_user_restriction((int) $module->cmid, [(int) $teachersonly->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $granted->id, (int) $alsogranted->id]);

        $userlist = new userlist(\context_module::instance($module->cmid), 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing(
            [(int) $granted->id, (int) $alsogranted->id],
            array_map('intval', $userlist->get_userids()),
            'Only the ids in the plugin\'s own node; the teacher\'s student is not the plugin\'s to report.'
        );
    }

    /**
     * The export names the activity the rule opened for the user.
     *
     * @return void
     */
    public function test_the_export_names_the_gated_activity(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Reinforcement']);
        $this->gate_module($course, (int) $module->cmid, [(int) $grantee->id]);

        $context = \context_module::instance($module->cmid);
        $this->export_context_data_for_user((int) $grantee->id, $context, 'local_coursedynamicrules');

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data(), 'The approved context exported nothing at all.');

        $exported = $writer->get_data([get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]);
        $this->assertTrue($exported->granted);
        $this->assertSame(
            ['Enable rule'],
            $exported->rules,
            'The export must name the rule that opened the activity, not merely that something did.'
        );
    }

    /**
     * When the rule behind a gate is gone, the export says so rather than exporting an empty name.
     *
     * A marker can outlive its action - a course import brings activities without rules - and the
     * access is the fact the data subject is entitled to either way. Exporting a blank would read as
     * though the plugin had lost the record rather than as what it is.
     *
     * @return void
     */
    public function test_the_export_says_so_when_the_owning_rule_is_gone(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $action = $this->gate_module($course, (int) $module->cmid, [(int) $grantee->id]);

        // The gate keeps its marker; the action it names does not exist any more.
        $DB->delete_records('local_coursedynamicrules_action', ['id' => $action->get_id()]);

        $context = \context_module::instance($module->cmid);
        $this->export_context_data_for_user((int) $grantee->id, $context, 'local_coursedynamicrules');

        $exported = writer::with_context($context)
            ->get_data([get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]);
        $this->assertSame(
            [get_string('privacy:export:ruledeleted', 'local_coursedynamicrules')],
            $exported->rules
        );
    }

    /**
     * Erasure takes the user's id out of the gate, keeps the gate, and leaves everyone else in it.
     *
     * The three assertions are three different failures. Removing nothing is the bug this class
     * exists to fix. Removing the whole node opens the activity to the entire course, because an
     * AND tree with no restriction restricts nobody. Removing somebody else's id takes away access
     * that was never part of the request.
     *
     * @return void
     */
    public function test_erasure_removes_the_users_id_and_keeps_the_gate_closed(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $requester = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bystander = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teachersonly = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $untouched = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->set_unmarked_user_restriction((int) $module->cmid, [(int) $requester->id, (int) $teachersonly->id]);
        $this->set_unmarked_user_restriction((int) $untouched->cmid, [(int) $requester->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $requester->id, (int) $bystander->id]);

        provider::delete_data_for_user($this->approve_all_for((int) $requester->id));

        [$marked, $unmarked] = $this->user_nodes((int) $module->cmid);
        $this->assertCount(1, $marked, 'The gate itself must survive: a tree without it restricts nobody.');
        $this->assertSame(
            [(int) $bystander->id],
            $this->ids_of($marked[0]),
            'The requester leaves the gate; the other grantee keeps the access nobody asked to remove.'
        );
        $this->assertCount(1, $unmarked);
        $this->assertEqualsCanonicalizing(
            [(int) $requester->id, (int) $teachersonly->id],
            $this->ids_of($unmarked[0]),
            'The teacher\'s own restriction is not this plugin\'s to erase from.'
        );
        $this->assertSame(
            [(int) $requester->id],
            $this->ids_of($this->user_nodes((int) $untouched->cmid)[1][0]),
            'A module the plugin never gated is not touched at all.'
        );
    }

    /**
     * The gate students are evaluated against is invalidated, not just the column.
     *
     * The availability tree that decides access is read from modinfo, so a write nobody invalidates
     * erases the id in the database and changes nothing a student experiences until some unrelated
     * edit happens to rebuild the cache. The cache is primed BEFORE the erasure on purpose: without
     * that, the assertion would pass against a fresh read and prove nothing.
     *
     * The assertions decode the cached tree and read its list of ids. An earlier version of this
     * test searched the cached JSON for the id as a quoted string, which is a shape it never has -
     * the list holds bare integers - so the needle was already absent before the erasure ran and the
     * test passed against a provider that wrote nothing at all. It is written down here because a
     * green test that cannot fail is worse than no test: it retires the question.
     *
     * @return void
     */
    public function test_erasure_invalidates_the_course_cache(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $requester = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $requester->id]);

        $cachedids = static function (\stdClass $course, int $cmid): array {
            $tree = json_decode((string) get_fast_modinfo($course->id)->get_cm($cmid)->availability);
            $ids = [];
            foreach ($tree->c ?? [] as $node) {
                if (($node->type ?? null) === 'user' && isset($node->source)) {
                    $ids = array_merge($ids, array_map('intval', (array) $node->userids));
                }
            }
            return $ids;
        };

        $this->assertSame(
            [(int) $requester->id],
            $cachedids($course, (int) $module->cmid),
            'Sanity: the cached gate must list the user before the erasure, or this test proves nothing.'
        );

        provider::delete_data_for_user($this->approve_all_for((int) $requester->id));

        $this->assertSame(
            [],
            $cachedids($course, (int) $module->cmid),
            'The cached gate still lists the erased user: the course cache was not invalidated.'
        );
    }

    /**
     * Erasing a list of users takes out exactly those, in the one context given.
     *
     * @return void
     */
    public function test_erasing_a_list_of_users_is_scoped_to_those_users(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $keeper = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $first->id, (int) $second->id, (int) $keeper->id]);

        $approved = new approved_userlist(
            \context_module::instance($module->cmid),
            'local_coursedynamicrules',
            [(int) $first->id, (int) $second->id]
        );
        provider::delete_data_for_users($approved);

        [$marked] = $this->user_nodes((int) $module->cmid);
        $this->assertSame([(int) $keeper->id], $this->ids_of($marked[0]));
    }

    /**
     * Erasing the whole context empties the gate and keeps it: the activity stays closed.
     *
     * @return void
     */
    public function test_erasing_the_whole_context_empties_the_gate_but_keeps_it(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $teachersstudent = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->set_unmarked_user_restriction((int) $module->cmid, [(int) $teachersstudent->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $first->id, (int) $second->id]);

        provider::delete_data_for_all_users_in_context(\context_module::instance($module->cmid));

        [$marked, $unmarked] = $this->user_nodes((int) $module->cmid);
        $this->assertCount(1, $marked, 'The gate must still be there, holding nobody.');
        $this->assertSame([], $this->ids_of($marked[0]));
        $this->assertSame(
            [(int) $teachersstudent->id],
            $this->ids_of($unmarked[0]),
            'Erasing the context is still not a licence to rewrite another component\'s restriction.'
        );
    }

    /**
     * A gate whose id list was stored with gaps is still found and still erased.
     *
     * json_decode() gives back a stdClass, not an array, whenever the stored list has string keys or
     * gaps - which is exactly what a gapped PHP array encodes to, and the enable-activity action's
     * own cleanup re-indexes precisely to avoid producing one. If such a list is read as empty, the
     * module drops out of the contextlist, and the contextlist is the attestation: the student would
     * be told their data was erased while these ids stayed. Recovering the shape is the difference
     * between a conservative provider and a lying one.
     *
     * @return void
     */
    public function test_a_gapped_id_list_is_still_found_and_erased(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $requester = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $keeper = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $requester->id, (int) $keeper->id]);

        // Rewrite our own gate's list with gaps, which is what makes json_decode() return an object.
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $module->cmid]));
        $node = enableactivity_action::owned_user_nodes($tree)[0];
        $node->userids = (object) [0 => (int) $requester->id, 2 => (int) $keeper->id];
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $module->cmid]);
        $this->assertStringContainsString(
            '"userids":{',
            (string) $DB->get_field('course_modules', 'availability', ['id' => $module->cmid]),
            'Sanity: the list must really be stored as an object, or this test exercises nothing.'
        );

        $this->assertSame(
            [(int) \context_module::instance($module->cmid)->id],
            array_map('intval', provider::get_contexts_for_userid((int) $requester->id)->get_contextids()),
            'The ids are there, so the module is one of the user\'s contexts however the list was stored.'
        );

        provider::delete_data_for_user($this->approve_all_for((int) $requester->id));

        [$marked] = $this->user_nodes((int) $module->cmid);
        $this->assertSame([(int) $keeper->id], $this->ids_of($marked[0]));
    }

    /**
     * A context that is not a module is refused rather than acted on by accident.
     *
     * @return void
     */
    public function test_a_non_module_context_is_ignored(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $student->id]);

        $userlist = new userlist(\context_course::instance($course->id), 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));
        [$marked] = $this->user_nodes((int) $module->cmid);
        $this->assertSame([(int) $student->id], $this->ids_of($marked[0]), 'A course context must not empty a module gate.');
    }
}
