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
     * The ids CORE reads from a node, mirroring availability_user's own constructor.
     *
     * That constructor takes `userids` AND, for backwards compatibility, pushes a singular `userid`
     * on top of it (availability/condition/user/classes/condition.php). A test that only looked at
     * `userids` would agree with the bug instead of catching it, so the assertions below ask the
     * question core asks: which students does this restriction actually name?
     *
     * @param \stdClass $node A user restriction node.
     * @return int[]
     */
    private function ids_core_reads(\stdClass $node): array {
        $ids = [];
        foreach ((array) ($node->userids ?? []) as $id) {
            $ids[] = (int) $id;
        }
        if (isset($node->userid)) {
            $ids[] = (int) $node->userid;
        }
        return $ids;
    }

    /**
     * Rewrite this plugin's own gate on a module into a given raw shape, keeping its marker.
     *
     * @param int $cmid The module.
     * @param array $keys The keys to set on the owned node, replacing userids/userid.
     * @return void
     */
    private function reshape_own_gate(int $cmid, array $keys): void {
        global $DB;
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $node = enableactivity_action::owned_user_nodes($tree)[0];
        unset($node->userids, $node->userid);
        foreach ($keys as $key => $value) {
            $node->{$key} = $value;
        }
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
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
        $this->assertSame(1, $exported->restrictions, 'One restriction of ours holds this student.');
        $this->assertSame(
            ['Enable rule'],
            $exported->rules,
            'The export must name the rule associated with the restriction, not merely that one exists.'
        );
        $this->assertFalse(
            property_exists($exported, 'granted'),
            'The export must not state an access outcome: it is not computable from a node, and it was '
                . 'measured wrong in the commonest tree there is.'
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
     * A gate written in the legacy singular shape is found and erased like any other.
     *
     * availability_user has always accepted `{"type":"user","userid":42}` and still does: its
     * constructor pushes that key onto the list it evaluates. A node in that shape restricts the
     * activity to user 42 exactly as `userids:[42]` would, and it can carry this plugin's marker,
     * because the marker is stamped on a node chosen by TYPE and nothing about the stamping looks
     * at the shape inside.
     *
     * A provider blind to that key reports no context for the student, so the module is never
     * approved, nothing is erased, and tool_dataprivacy tells the student their data was deleted.
     * That is the exact failure this class exists to end, reappearing in a shape nobody checked.
     *
     * @return void
     */
    public function test_a_legacy_single_userid_gate_is_found_and_erased(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $requester = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $requester->id]);
        $this->reshape_own_gate((int) $module->cmid, ['userid' => (int) $requester->id]);

        $userlist = new userlist(\context_module::instance($module->cmid), 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertSame(
            [(int) $requester->id],
            array_map('intval', $userlist->get_userids()),
            'The student named by the legacy key is in the gate, so the provider must report them.'
        );

        $this->assertSame(
            [(int) \context_module::instance($module->cmid)->id],
            array_map('intval', provider::get_contexts_for_userid((int) $requester->id)->get_contextids()),
            'A module whose gate names the student is one of their contexts, whichever key names them.'
        );

        provider::delete_data_for_user($this->approve_all_for((int) $requester->id));

        [$marked] = $this->user_nodes((int) $module->cmid);
        $this->assertCount(1, $marked, 'The gate itself must survive: a tree without it restricts nobody.');
        $this->assertSame(
            [],
            $this->ids_core_reads($marked[0]),
            'Core still reads the erased student out of this gate: the legacy key was left behind.'
        );
    }

    /**
     * A gate carrying BOTH keys loses only the requested student, from whichever key named them.
     *
     * This is the shape that punishes a half fix. Rewriting `userids` while leaving `userid` alone
     * removes the student from the list the provider looked at and not from the list core reads.
     *
     * @return void
     */
    public function test_a_gate_carrying_both_keys_loses_only_the_requested_student(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $requester = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $keeper = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $requester->id]);
        $this->reshape_own_gate(
            (int) $module->cmid,
            ['userids' => [(int) $keeper->id], 'userid' => (int) $requester->id]
        );

        provider::delete_data_for_user($this->approve_all_for((int) $requester->id));

        [$marked] = $this->user_nodes((int) $module->cmid);
        $this->assertSame(
            [(int) $keeper->id],
            $this->ids_core_reads($marked[0]),
            'The other student keeps the access nobody asked to remove, and the requester is gone from both keys.'
        );
    }

    /**
     * Wrap this plugin's own gate in one or more groups, innermost operator first.
     *
     * @param int $cmid The module.
     * @param string[] $ops The operators to nest the gate under, innermost first.
     * @return void
     */
    private function nest_own_gate_under(int $cmid, array $ops): void {
        global $DB;
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $owned = enableactivity_action::owned_user_nodes($tree)[0];
        $tree->c = array_values(array_filter($tree->c, static function ($node) use ($owned): bool {
            return $node !== $owned;
        }));
        $nested = $owned;
        foreach ($ops as $op) {
            $nested = (object) ['op' => $op, 'c' => [$nested], 'showc' => [false]];
        }
        $tree->c[] = $nested;
        $tree->showc = array_fill(0, count($tree->c), false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * The module's stored availability JSON, unparsed.
     *
     * @param int $cmid The module.
     * @return string
     */
    private function raw_availability(int $cmid): string {
        global $DB;
        return (string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
    }

    /**
     * The ids this plugin's own gate lists, wherever in the tree the gate ended up.
     *
     * Deliberately not the class under test's own walk: an assertion that used the provider's notion
     * of "our nodes" would agree with the provider by construction and could never catch it skipping
     * one. This finds a marked node anywhere, by the marker alone.
     *
     * @param int $cmid The module.
     * @return int[]
     */
    private function ids_anywhere(int $cmid): array {
        global $DB;
        $found = [];
        $walk = static function ($node) use (&$walk, &$found): void {
            if (($node->type ?? null) === 'user' && isset($node->source)) {
                foreach ((array) ($node->userids ?? []) as $id) {
                    $found[] = (int) $id;
                }
            }
            foreach ((array) ($node->c ?? []) as $child) {
                if (is_object($child)) {
                    $walk($child);
                }
            }
        };
        $walk(json_decode($DB->get_field('course_modules', 'availability', ['id' => $cmid])));
        return $found;
    }

    /**
     * A gate nested under a negating group is claimed and erased like any other.
     *
     * This test exists because the opposite was implemented first, on a measurement that was never
     * taken. Under a negation the node lists the students KEPT OUT, and it reads as though emptying
     * it would open the activity to the whole course - so the provider was made to decline such a
     * node entirely.
     *
     * Simulating core's own tree logic over a cohort showed that is false. A user condition
     * contributes `$not XOR in_array($userid, $userids)`, which consults no other student, so
     * removing ids changes the evaluation for THOSE ids and for nobody else: with a node listing
     * one student under `!&`, everybody else was already getting in before the change and still is
     * after it. Declining the node protected nothing, and it kept a person's id in the database
     * after their request had been reported as completed - which is the failure this whole class
     * exists to end.
     *
     * (Deleting the node outright is a different matter and is still refused: an AND tree that loses
     * a restriction stops restricting, which genuinely does open the activity to everyone. That is
     * why the erasure empties the list and keeps the node, here as everywhere else.)
     *
     * @return void
     */
    public function test_a_gate_under_a_negating_group_is_claimed_and_erased(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $student->id]);
        $this->nest_own_gate_under((int) $module->cmid, ['!&']);

        $this->assertSame(
            [(int) \context_module::instance($module->cmid)->id],
            array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids()),
            'The student id is in the database and the plugin wrote it, so the module is one of their contexts.'
        );

        $userlist = new userlist(\context_module::instance($module->cmid), 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $student->id], array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_user($this->approve_all_for((int) $student->id));

        $this->assertSame(
            [],
            $this->ids_anywhere((int) $module->cmid),
            'The id must be gone: a context listed is a context promised.'
        );

        // And the node itself survives, nested where it was.
        $tree = json_decode($this->raw_availability((int) $module->cmid));
        $this->assertSame('!&', $tree->c[0]->op ?? null, 'The negated group and the node inside it must both remain.');
    }

    /**
     * The export must not name a rule of another course just because the id happens to exist.
     *
     * A course import brings activities without rules - the plugin documents that as intended - so a
     * module can arrive carrying a gate stamped with an action id from wherever it was exported. All
     * action ids of the site share one table and one number space, so that id very probably belongs
     * to a live action of a DIFFERENT rule, in a DIFFERENT course.
     *
     * Resolving the marker by id alone therefore answers "which rule opened this for you?" with the
     * name of a rule that never did. That is a false statement inside a subject access response,
     * which is the one document where an invented answer is worse than no answer.
     *
     * @return void
     */
    public function test_the_export_does_not_name_a_rule_from_another_course(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Course A owns a real rule and a real action; its id is what the imported marker will carry.
        $coursea = $this->getDataGenerator()->create_course();
        $modulea = $this->getDataGenerator()->create_module('page', ['course' => $coursea->id]);
        $actiona = $this->gate_module($coursea, (int) $modulea->cmid, []);
        $DB->set_field('local_coursedynamicrules_rule', 'name', 'Rule of another course', ['courseid' => $coursea->id]);

        // Course B receives an imported activity whose gate still names course A's action, and no rule.
        $courseb = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($courseb, 'student');
        $moduleb = $this->getDataGenerator()->create_module('page', ['course' => $courseb->id]);
        $node = enableactivity_action::mark_node(
            (object) ['type' => 'user', 'userids' => [(int) $student->id]],
            (int) $actiona->get_id()
        );
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json([$node], tree::OP_AND, false)),
            ['id' => $moduleb->cmid]
        );

        $context = \context_module::instance($moduleb->cmid);
        $this->export_context_data_for_user((int) $student->id, $context, 'local_coursedynamicrules');
        $exported = writer::with_context($context)
            ->get_data([get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]);

        $this->assertSame(
            [get_string('privacy:export:ruledeleted', 'local_coursedynamicrules')],
            $exported->rules,
            'The export named a rule of another course, which is not associated with this restriction.'
        );
    }

    /**
     * A course is invalidated once per erasure, however many of its activities changed.
     *
     * Every invalidation bumps the course's cache revision and throws away the cached settings of
     * the WHOLE course, so doing it per activity makes a student granted a dozen activities pay a
     * dozen full invalidations inside one request, and every other user of that course rebuild from
     * scratch a dozen times over. The plugin's own user-deletion observer already refuses to do that
     * and says why: "One cache rebuild per course that changed, however many actions it holds."
     *
     * Asserted as the DIFFERENCE in database writes between erasing three gates and erasing one, in
     * one course each, because an absolute count would depend on how much unrelated bookkeeping a
     * write happens to do. Three gates cost two extra column writes than one gate does. If each gate
     * also invalidated the course, they would cost two more on top of that - the revision bump is a
     * write of its own (increment_revision_number, lib/datalib.php). So the difference is 2 when the
     * invalidation is per course and 4 when it is per activity.
     *
     * Not measured on cacherev itself: increment_revision_number is time-based - it jumps the value
     * to time() when it is behind and only adds one when it is not - so "the revision moved" cannot
     * tell one invalidation from three, and a test built on it would pass either way.
     *
     * @return void
     */
    public function test_a_course_is_invalidated_once_however_many_of_its_activities_changed(): void {
        global $DB;
        $this->resetAfterTest(true);

        $writes = function (int $modulecount): int {
            global $DB;
            $course = $this->getDataGenerator()->create_course();
            $requester = $this->getDataGenerator()->create_and_enrol($course, 'student');
            for ($i = 0; $i < $modulecount; $i++) {
                $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
                $this->gate_module($course, (int) $module->cmid, [(int) $requester->id]);
            }
            $approved = $this->approve_all_for((int) $requester->id);
            $this->assertCount($modulecount, $approved->get_contextids(), 'Sanity: every gate must be approved.');

            $before = $DB->perf_get_writes();
            provider::delete_data_for_user($approved);

            return $DB->perf_get_writes() - $before;
        };

        $one = $writes(1);
        $three = $writes(3);

        $this->assertSame(
            2,
            $three - $one,
            'Three gates in one course cost two extra writes than one gate, and no more: a fourth and '
                . 'a fifth would be a second and third invalidation of the same course.'
        );
    }

    /**
     * The declared limit, as an executable fact: when core erases the stamp, the student vanishes.
     *
     * Every other test in this file pins a behaviour. This one pins a LIMITATION, on purpose, and it
     * is the most important test here because it measures the root cause rather than one of its
     * symptoms.
     *
     * The root cause is one sentence: this plugin records who owns a restriction by putting a
     * property of its own inside {course_modules}.availability, a structure core owns and rebuilds
     * from each condition's save() - and availability_user::save() emits {type, userids} and nothing
     * else. Any property the plugin added is gone. That is not a bug in this provider; it is the
     * condition the provider lives under, and it is why ownership can only ever be claimed and never
     * assumed.
     *
     * The mechanism used below is the real one, not a simulation: shifting a course's dates is what
     * a course reset does, and it makes core rewrite the whole tree. The same erasure happens when a
     * teacher opens the activity's settings and saves.
     *
     * The two assertions together ARE the limitation, and neither is meaningful alone:
     *
     *   1. The student's id is STILL in the database. Nothing was cleaned.
     *   2. The provider reports nothing. It cannot prove it wrote that restriction any more, and
     *      guessing would mean rewriting a restriction some teacher may own.
     *
     * So an erasure request covering this module would report success and leave the id. That is
     * declared in the class docblock and in CHANGES.md, and it is pinned here so it stops being
     * rediscovered as a new finding each time somebody reviews this file. It closes the day the
     * engine writes a condition type of its own, and not before: no amount of care inside this
     * provider can recover a property core has already discarded.
     *
     * @return void
     */
    public function test_when_core_erases_the_stamp_the_student_is_still_there_and_the_provider_cannot_see_them(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $student->id]);

        // A date condition beside our gate: it is what makes core walk and re-encode the whole tree.
        $tree = json_decode($this->raw_availability((int) $module->cmid));
        $tree->c[] = (object) ['type' => 'date', 'd' => '>=', 't' => 1700000000];
        $tree->showc = array_fill(0, count($tree->c), false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $module->cmid]);

        $this->assertSame(
            [(int) \context_module::instance($module->cmid)->id],
            array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids()),
            'Precondition: while the stamp is there the provider reaches the student.'
        );

        // What a course reset does. Core rebuilds the tree from each condition's own save().
        \availability_date\condition::update_all_dates((int) $course->id, 3600);

        $raw = $this->raw_availability((int) $module->cmid);
        $this->assertStringNotContainsString(
            enableactivity_action::marker_prefix(),
            $raw,
            'Precondition: core must really have discarded the stamp, or this test proves nothing.'
        );
        $this->assertStringContainsString(
            (string) $student->id,
            $raw,
            'The student id is STILL in the database. Nothing about this is a cleanup.'
        );

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $student->id)->get_contextids(),
            'And the provider can no longer prove it wrote this restriction, so it claims nothing - '
                . 'which means an erasure request would report success and leave the id in place.'
        );
    }

    /**
     * The export says the same thing whether or not the student can actually open the activity.
     *
     * The method used to report `granted => true`, and the failure was not an exotic one. A teacher
     * restricts an activity by date; a rule opens it for one student. That pairing is the whole point
     * of this plugin, and until the date arrives the student cannot open it - while the export told
     * them access had been granted.
     *
     * The assertion is made against CORE's own verdict rather than against a hand-reasoned one, so
     * the test fails if Moodle ever changes how it combines restrictions: first prove the student is
     * shut out, then prove the export does not claim otherwise.
     *
     * @return void
     */
    public function test_the_export_claims_no_access_when_core_shuts_the_student_out(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $student->id]);

        // A date restriction the teacher set, which has not arrived. Ordinary, and not a negation.
        $tree = json_decode($this->raw_availability((int) $module->cmid));
        $tree->c[] = (object) ['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS];
        $tree->showc = array_fill(0, count($tree->c), false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $module->cmid]);
        rebuild_course_cache((int) $course->id, true);

        $cm = get_fast_modinfo($course->id)->get_cm($module->cmid);
        $information = '';
        $this->assertFalse(
            (new \core_availability\info_module($cm))->is_available($information, false, (int) $student->id),
            'Precondition: core must really be shutting this student out, or the test proves nothing.'
        );

        $context = \context_module::instance($module->cmid);
        $this->export_context_data_for_user((int) $student->id, $context, 'local_coursedynamicrules');
        $exported = writer::with_context($context)
            ->get_data([get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]);

        $this->assertFalse(
            property_exists($exported, 'granted'),
            'The export told a student who cannot open this activity that access had been granted.'
        );
        $this->assertSame(1, $exported->restrictions, 'The id IS stored here, and that is what is disclosed.');
    }

    /**
     * Two rules that happen to share a name are reported as two restrictions, not one.
     *
     * The rules used to be de-duplicated on their NAME, and a course copy makes same-named rules
     * ordinary rather than contrived. The reader was then told about one restriction when four held
     * their id, with no way to tell the difference - and an export that undercounts what is stored is
     * the same defect as one that miscounts it.
     *
     * @return void
     */
    public function test_two_rules_sharing_a_name_are_not_collapsed_into_one(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // Two separate actions, each with its own rule, gating the same activity for the same student.
        $this->gate_module($course, (int) $module->cmid, [(int) $student->id]);
        $this->gate_module($course, (int) $module->cmid, [(int) $student->id]);
        $DB->set_field('local_coursedynamicrules_rule', 'name', 'Enable rule', ['courseid' => $course->id]);

        [$marked] = $this->user_nodes((int) $module->cmid);
        $this->assertCount(2, $marked, 'Precondition: the activity must really carry two gates of ours.');

        $context = \context_module::instance($module->cmid);
        $this->export_context_data_for_user((int) $student->id, $context, 'local_coursedynamicrules');
        $exported = writer::with_context($context)
            ->get_data([get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]);

        $this->assertCount(
            2,
            $exported->rules,
            'Two distinct rules that share a name were reported as one, so the reader cannot tell them apart.'
        );
        $this->assertSame(2, $exported->restrictions, 'Two restrictions hold this student, not one.');
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
