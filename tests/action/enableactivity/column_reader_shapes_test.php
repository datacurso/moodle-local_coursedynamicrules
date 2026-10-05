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

/**
 * The three readers of {course_modules}.availability have to agree about what is inside it.
 *
 * A user restriction can reach this plugin in three shapes, and only one of them is the shape the
 * plugin writes:
 *
 * 1. A plain list - {"userids":[501,777]} - which is what every writer here produces.
 * 2. A JSON OBJECT - {"userids":{"0":501,"2":777}} - which is how PHP encodes a list whose integer
 *    keys have gaps, and gaps are what array_filter() leaves behind when it removes an element
 *    without re-indexing. Read through is_array() alone such a list looks EMPTY; read through
 *    in_array() it is a TypeError under PHP 8.
 * 3. The legacy SINGULAR key - {"userid":501} - which availability_user still honours: its
 *    constructor pushes $structure->userid onto the list it evaluates
 *    (availability/condition/user/classes/condition.php). A reader that only looks at the plural key
 *    does not see that student at all.
 *
 * revoke_user() and the privacy provider's userids_of() both recover shapes 2 and 3. execute() -
 * the third reader, and the one the rule engine runs on every pass - recovers neither, and it runs
 * inside three scheduled tasks that have no try/catch of their own: a Throwable there is caught by
 * core, which marks the whole task failed, so every rule after it in every course of the site stops
 * for that pass and the task is backed off exponentially on the next.
 *
 * Shapes 2 and 3 are not written by this plugin - every writer here re-indexes, and core rejects an
 * object outright because availability_user types its property as array - so shape 2 arrives only
 * from data edited outside the plugin. Shape 3 arrives from any node written against the legacy key.
 * The point of these tests is not how likely each is: it is that one reader of a column may not
 * disagree with the other two about what the column holds.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\action\enableactivity\enableactivity_action::execute
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class column_reader_shapes_test extends \advanced_testcase {
    /**
     * Put a hand-built user-restriction node on a module, as the only condition of an AND root.
     *
     * Written as raw JSON rather than through tree::get_root_json() on purpose: the shapes under
     * test are precisely the ones PHP's encoder would normalise away on the way in.
     *
     * @param int $cmid The module.
     * @param string $nodejson The user node, as it is stored.
     * @return void
     */
    private function store_user_node(int $cmid, string $nodejson): void {
        global $DB;
        $DB->set_field(
            'course_modules',
            'availability',
            '{"op":"&","c":[' . $nodejson . '],"showc":[false]}',
            ['id' => $cmid]
        );
    }

    /**
     * An action that manages the given module.
     *
     * @param int $cmid The module.
     * @param int $courseid The course.
     * @return enableactivity_action
     */
    private function action_for(int $cmid, int $courseid): enableactivity_action {
        $record = (object) [
            'ruleid' => 1,
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [['id' => $cmid, 'visible' => 1, 'visibleoncoursepage' => 1]]]),
        ];
        return new enableactivity_action($record, $courseid);
    }

    /**
     * Every user id the module's restriction names, whichever key holds it, as availability_user reads it.
     *
     * @param int $cmid The module.
     * @return int[] Sorted, duplicates kept - a duplicate is a defect worth seeing.
     */
    private function ids_as_core_reads_them(int $cmid): array {
        global $DB;
        $node = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]))->c[0];
        $ids = array_map('intval', array_values((array) ($node->userids ?? [])));
        if (isset($node->userid)) {
            $ids[] = (int) $node->userid;
        }
        sort($ids);
        return $ids;
    }

    /**
     * Run the action for one user, reporting a Throwable as the defect it is rather than as an error.
     *
     * @param enableactivity_action $action The action.
     * @param int $courseid The course.
     * @param int $userid The user the rule matched.
     * @return void
     */
    private function execute_for(enableactivity_action $action, int $courseid, int $userid): void {
        try {
            $action->execute((object) ['courseid' => $courseid, 'userid' => $userid]);
        } catch (\Throwable $e) {
            $this->fail(
                'execute() died reading the stored user list: ' . get_class($e) . ' - ' . $e->getMessage()
                . '. The scheduled tasks do not catch this, so core marks the whole task failed and every '
                . 'rule after this one, in every course, stops running.'
            );
        }
    }

    /**
     * A list stored as a JSON object does not stop the rule engine.
     *
     * @return void
     */
    public function test_a_user_list_stored_as_an_object_does_not_kill_the_run(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $granted = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // What a gapped list looks like once it has been through json_encode().
        $this->store_user_node((int) $module->cmid, '{"type":"user","userids":{"0":' . (int) $granted->id . '}}');

        $this->execute_for($this->action_for((int) $module->cmid, (int) $course->id), (int) $course->id, (int) $granted->id);

        $this->assertSame(
            [(int) $granted->id],
            $this->ids_as_core_reads_them((int) $module->cmid),
            'The student was already granted, so the run must leave the list exactly as it found it.'
        );
    }

    /**
     * Other students already granted through an object-shaped list are not lost when a new one is added.
     *
     * This is the regression the repair itself could introduce, and it has happened once already in
     * this class: reading the object through is_array() alone makes the list look empty, the new id
     * is appended to nothing, and everybody else's access disappears without an error. The same
     * mistake in revoke_user() cost every other student on the gate their access.
     *
     * @return void
     */
    public function test_granting_through_an_object_shaped_list_keeps_everyone_already_in_it(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $newcomer = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // Keys 0 and 2: what array_filter() leaves when the id at key 1 is removed.
        $this->store_user_node(
            (int) $module->cmid,
            '{"type":"user","userids":{"0":' . (int) $first->id . ',"2":' . (int) $second->id . '}}'
        );

        $this->execute_for($this->action_for((int) $module->cmid, (int) $course->id), (int) $course->id, (int) $newcomer->id);

        $expected = [(int) $first->id, (int) $second->id, (int) $newcomer->id];
        sort($expected);
        $this->assertSame(
            $expected,
            $this->ids_as_core_reads_them((int) $module->cmid),
            'Granting to one student dropped the students the restriction already named.'
        );
    }

    /**
     * A student named by the legacy singular key is recognised instead of being granted twice.
     *
     * availability_user pushes that key onto the list it evaluates, so the student already has
     * access; a reader that cannot see it adds the id again and the restriction ends up naming the
     * same person twice. The access is unchanged - this one is about the plugin writing a grant it
     * had already made, and about the three readers disagreeing.
     *
     * @return void
     */
    public function test_a_student_named_by_the_legacy_singular_key_is_not_granted_twice(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $granted = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->store_user_node((int) $module->cmid, '{"type":"user","userid":' . (int) $granted->id . '}');

        $this->execute_for($this->action_for((int) $module->cmid, (int) $course->id), (int) $course->id, (int) $granted->id);

        $this->assertSame(
            [(int) $granted->id],
            $this->ids_as_core_reads_them((int) $module->cmid),
            'The student is named by the singular key and already has access, so the run must not '
                . 'name them a second time through the plural one.'
        );
    }

    /**
     * An id stored as a string is still that student, and they are not granted a second time.
     *
     * Green before the repair and green after it, deliberately. The stored list can hold strings -
     * execute() keeps whatever the rule engine handed it, which is stated in revoke_user() - and the
     * comparison that finds them today is a LOOSE in_array(). Any repair that tightens it into a
     * strict comparison starts appending ids that are already there, silently, on every cron pass.
     * This pins the behaviour the repair has to preserve rather than the defect it has to remove.
     *
     * @return void
     */
    public function test_a_student_stored_as_a_string_is_not_granted_again(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $granted = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->store_user_node((int) $module->cmid, '{"type":"user","userids":["' . (int) $granted->id . '"]}');

        $this->execute_for($this->action_for((int) $module->cmid, (int) $course->id), (int) $course->id, (int) $granted->id);

        $this->assertSame(
            [(int) $granted->id],
            $this->ids_as_core_reads_them((int) $module->cmid),
            'A student whose id is stored as a string was granted a second time: the comparison '
                . 'stopped recognising the shape the engine itself can store.'
        );
    }

    /**
     * A node carrying BOTH keys names the student once, and the plural list keeps its own members.
     *
     * The shape where the two halves of this defect meet: availability_user adds the singular key to
     * the list it evaluates, so the student already has access, and the plural list holds somebody
     * else who must not be disturbed by reading around them.
     *
     * @return void
     */
    public function test_a_node_carrying_both_keys_grants_nobody_twice_and_loses_nobody(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $legacy = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $listed = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->store_user_node(
            (int) $module->cmid,
            '{"type":"user","userid":' . (int) $legacy->id . ',"userids":[' . (int) $listed->id . ']}'
        );

        $this->execute_for($this->action_for((int) $module->cmid, (int) $course->id), (int) $course->id, (int) $legacy->id);

        $expected = [(int) $legacy->id, (int) $listed->id];
        sort($expected);
        $this->assertSame(
            $expected,
            $this->ids_as_core_reads_them((int) $module->cmid),
            'The student named by the singular key was granted again through the plural list, or the '
                . 'student already listed there was lost while reading around them.'
        );
    }
}
