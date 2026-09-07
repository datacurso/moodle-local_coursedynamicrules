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

namespace local_coursedynamicrules\helper;

use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\core\rule;

/**
 * Duplicating a rule - the lock's official escape hatch.
 *
 * Traced from Workplace's tool_dynamicrule (api.php:288-322, duplicate_rule): the copy is born
 * DISABLED under a distinct name, with the configuration copied and the runtime state left
 * behind. For this plugin that means: active 0, timeactivated null (a copy was never activated -
 * duplicating a sealed rule is precisely how you edit its ideas), lastexecutiontime null on the
 * rule and every copied component, and a "(copy)" name that stays unique per course.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\helper\rule_duplicator
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_duplicator_test extends \advanced_testcase {
    /**
     * Build a sealed rule with one condition and one action, both carrying runtime state.
     *
     * @param int $courseid
     * @return int Rule id.
     */
    private function sealed_rule(int $courseid): int {
        global $DB;

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Sealed original',
            'description' => 'The ideas worth copying',
            'active' => 1,
            'timeactivated' => 7777,
            'lastexecutiontime' => 5000,
            'timecreated' => 1000,
            'timemodified' => 2000,
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'name' => 'no_course_access',
            'conditiontype' => 'no_course_access',
            'params' => json_encode(['periodvalue' => 3, 'periodunit' => 'days', 'nexttimeperiod' => 123]),
            'lastexecutiontime' => 4000,
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => 'sendnotification',
            'actiontype' => 'sendnotification',
            'params' => json_encode(['messagesubject' => 'S', 'messagebody' => 'B', 'primaryroleids' => [5]]),
            'lastexecutiontime' => 4000,
        ]);

        return $ruleid;
    }

    /**
     * The copy is an editable draft: inactive, unsealed, runtime reset, configuration whole.
     *
     * @covers ::duplicate
     */
    public function test_duplicating_a_sealed_rule_births_an_editable_draft(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $context = \context_course::instance($courseid);
        $ruleid = $this->sealed_rule($courseid);

        $sink = $this->redirectEvents();
        $newid = rule_duplicator::duplicate($ruleid, $courseid, $context);
        $events = $sink->get_events();
        $sink->close();

        $this->assertNotEquals($ruleid, $newid);
        $copy = $DB->get_record('local_coursedynamicrules_rule', ['id' => $newid], '*', MUST_EXIST);
        $this->assertEquals(0, $copy->active, 'The copy is born inactive, like Workplace\'s.');
        $this->assertNull($copy->timeactivated, 'A copy was never activated: unsealed, fully editable.');
        $this->assertNull($copy->lastexecutiontime, 'Runtime state stays with the original.');
        $this->assertSame('Sealed original (copy)', $copy->name);
        $this->assertSame('The ideas worth copying', $copy->description);

        // The configuration travels whole; the runtime column does not.
        $conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $newid]);
        $this->assertCount(1, $conditions);
        $condition = reset($conditions);
        $this->assertSame('no_course_access', $condition->conditiontype);
        $this->assertEquals(3, json_decode($condition->params)->periodvalue);
        $this->assertNull($condition->lastexecutiontime);

        $actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $newid]);
        $this->assertCount(1, $actions);
        $action = reset($actions);
        $this->assertSame('sendnotification', $action->actiontype);
        $this->assertEquals([5], json_decode($action->params)->primaryroleids);
        $this->assertNull($action->lastexecutiontime);

        // The original is untouched: still sealed, still active, runtime intact.
        $original = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(1, $original->active);
        $this->assertEquals(7777, $original->timeactivated);
        $this->assertEquals(5000, $original->lastexecutiontime);
        $this->assertCount(1, $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $ruleid]));

        // Duplication is a creation, audited as one.
        $created = array_filter($events, static function ($event) use ($newid): bool {
            return $event instanceof \local_coursedynamicrules\event\rule_created
                && (int) $event->objectid === $newid;
        });
        $this->assertCount(1, $created, 'The copy appears in the logs as a created rule.');

        // A second copy earns a numbered name instead of a collision.
        $secondid = rule_duplicator::duplicate($ruleid, $courseid, $context);
        $this->assertSame(
            'Sealed original (copy 2)',
            $DB->get_field('local_coursedynamicrules_rule', 'name', ['id' => $secondid], MUST_EXIST)
        );
    }

    /**
     * A foreign rule cannot be duplicated into this course - ownership speaks first, as always.
     *
     * @covers ::duplicate
     */
    public function test_a_foreign_rule_cannot_be_duplicated(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $mycourseid = (int) $this->getDataGenerator()->create_course()->id;
        $foreigncourseid = (int) $this->getDataGenerator()->create_course()->id;
        $foreignruleid = $this->sealed_rule($foreigncourseid);

        $this->expectException(\moodle_exception::class);
        rule_duplicator::duplicate($foreignruleid, $mycourseid, \context_course::instance($mycourseid));
    }

    /**
     * Build a sealed rule whose enable-activity action opens one module for one student. save_action()
     * gives the module the action's own marked gate and makes it visible, execute() grants the
     * student, and the rule is then stamped as activated: the end state of a rule that has run.
     *
     * @param \stdClass $course
     * @param int $cmid The module the rule opens.
     * @param int $userid The student already granted.
     * @return int Rule id.
     */
    private function rule_that_opens(\stdClass $course, int $cmid, int $userid): int {
        global $DB;

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Opens the reward',
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
        $action->execute((object) ['courseid' => $course->id, 'userid' => $userid]);
        $DB->set_field('local_coursedynamicrules_rule', 'timeactivated', time(), ['id' => $ruleid]);

        return $ruleid;
    }

    /**
     * Whether Moodle would let a user into a module right now, asked the way the course page asks.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    private function module_is_available_to(\stdClass $course, int $cmid, int $userid): bool {
        $info = new \core_availability\info_module(get_fast_modinfo($course)->get_cm($cmid));
        $information = '';
        return $info->is_available($information, false, $userid);
    }

    /**
     * The raw restriction and visibility of a module, to compare before and after byte for byte.
     *
     * @param int $cmid
     * @return array{availability: string|null, visible: int}
     */
    private function gate_of(int $cmid): array {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'availability, visible', MUST_EXIST);
        return ['availability' => $cm->availability, 'visible' => (int) $cm->visible];
    }

    /**
     * Skip when the availability plugin the enable-activity action writes for is not installed.
     */
    private function require_availability_user(): void {
        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; the enable-activity action requires it.');
        }
    }

    /**
     * A copy is never "Executed" and never sealed: the engine's auto-deactivation stamp and the
     * activation stamp stay with the original, so the list shows the copy as a plain draft and the
     * lock lets it be edited while the original stays sealed.
     *
     * @covers ::duplicate
     */
    public function test_the_copy_of_an_executed_rule_is_neither_executed_nor_sealed(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Ran once and stopped',
            'active' => 0,
            'timeactivated' => 7777,
            'timeautodeactivated' => 8888,
            'lastexecutiontime' => 8000,
            'timecreated' => 1000,
            'timemodified' => 2000,
        ]);

        $newid = rule_duplicator::duplicate($ruleid, $courseid, \context_course::instance($courseid));

        $copy = $DB->get_record('local_coursedynamicrules_rule', ['id' => $newid], '*', MUST_EXIST);
        $this->assertNull($copy->timeautodeactivated, 'The Executed stamp belongs to the run the original made.');
        $this->assertFalse(rule_lock::is_locked($newid), 'The copy is open to editing.');
        $this->assertTrue(rule_lock::is_locked($ruleid), 'Duplicating does not unseal the original.');
    }

    /**
     * A rule with no components duplicates into an equally empty draft: the ideas may still be in
     * the teacher's head, and the copy must not fail or invent components.
     *
     * @covers ::duplicate
     */
    public function test_a_rule_without_components_duplicates_into_an_empty_draft(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Still empty',
            'active' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $newid = rule_duplicator::duplicate($ruleid, $courseid, \context_course::instance($courseid));

        $copy = $DB->get_record('local_coursedynamicrules_rule', ['id' => $newid], '*', MUST_EXIST);
        $this->assertSame('Still empty (copy)', $copy->name);
        $this->assertEquals(0, $copy->active);
        $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition', ['ruleid' => $newid]));
        $this->assertSame(0, $DB->count_records('local_coursedynamicrules_action', ['ruleid' => $newid]));
    }

    /**
     * Names that fill the column, in single-byte and multibyte characters, and one that ends in a
     * language-string placeholder: the column counts characters, and so must the shortening.
     *
     * @return array<string, array{string}>
     */
    public static function full_length_names(): array {
        return [
            'single-byte' => [str_repeat('N', 255)],
            'multibyte' => [str_repeat('ñ', 255)],
            // get_string() substitutes placeholders inside the injected name too: a name ending in the
            // very token the numbered suffix uses must not throw the length off.
            'placeholder lookalike' => [str_repeat('A', 248) . '{$a->n}'],
        ];
    }

    /**
     * A rule whose name already fills the column can still be duplicated: the copy's name must fit
     * in the 255 characters the column holds and still be told apart from the source. Appending
     * " (copy)" to a full-length name overflows the column, and the database refuses the row.
     *
     * Two sources that differ only in their last characters shorten to the same base, so the second
     * copy must still earn a numbered name instead of colliding.
     *
     * @dataProvider full_length_names
     * @covers ::duplicate
     * @param string $name A 255-character rule name.
     */
    public function test_a_rule_whose_name_fills_the_column_can_still_be_duplicated(string $name): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $context = \context_course::instance($courseid);
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => $name,
            'active' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        // A sibling that differs only in its last character shortens to the very same base.
        $siblingid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => \core_text::substr($name, 0, 254) . 'X',
            'active' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $copyid = rule_duplicator::duplicate($ruleid, $courseid, $context);
        $siblingcopyid = rule_duplicator::duplicate($siblingid, $courseid, $context);

        $copyname = (string) $DB->get_field('local_coursedynamicrules_rule', 'name', ['id' => $copyid], MUST_EXIST);
        $this->assertLessThanOrEqual(255, \core_text::strlen($copyname), 'The copy\'s name must fit the column.');
        $this->assertNotSame($name, $copyname, 'The copy must still be told apart from the source.');
        $this->assertStringEndsWith(' (copy)', $copyname);
        $this->assertStringStartsWith(\core_text::substr($name, 0, 200), $copyname, 'The start of the name survives.');

        $siblingcopyname = (string) $DB->get_field('local_coursedynamicrules_rule', 'name', ['id' => $siblingcopyid], MUST_EXIST);
        $this->assertLessThanOrEqual(255, \core_text::strlen($siblingcopyname));
        $this->assertNotSame($copyname, $siblingcopyname, 'Two sources shortened to one base must not collide.');
        $this->assertStringEndsWith(' (copy 2)', $siblingcopyname);
    }

    /**
     * The copy of a rule that opens an activity starts with no activities, and the original's gate is
     * not touched. Each enable-activity action writes its own gate into the activity and Moodle
     * combines gates by AND, so a copy pointing at the same activity would either own nothing there or
     * close the activity to the students the original had opened it for. The teacher picks the
     * activities on the draft instead.
     *
     * @covers ::duplicate
     */
    public function test_the_copy_of_a_rule_that_opens_an_activity_starts_with_no_activities(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->require_availability_user();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reward = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = $this->rule_that_opens($course, (int) $reward->cmid, (int) $student->id);
        $before = $this->gate_of((int) $reward->cmid);
        $this->assertTrue(
            $this->module_is_available_to($course, (int) $reward->cmid, (int) $student->id),
            'Sanity: the original opened the activity for the student.'
        );

        $newid = rule_duplicator::duplicate($ruleid, (int) $course->id, \context_course::instance($course->id));

        $copied = $DB->get_record('local_coursedynamicrules_action', ['ruleid' => $newid], '*', MUST_EXIST);
        $this->assertSame('enableactivity', $copied->actiontype, 'The action itself travels.');
        $this->assertSame([], json_decode($copied->params)->coursemodules, 'The copy starts with no activities.');
        $this->assertSame($before, $this->gate_of((int) $reward->cmid), 'Duplicating writes nothing into the activity.');

        // The copied action running for another student changes nothing either: it manages no module.
        (new enableactivity_action($copied, (int) $course->id))->execute(
            (object) ['courseid' => $course->id, 'userid' => $other->id]
        );
        $this->assertDebuggingNotCalled();
        $this->assertSame($before, $this->gate_of((int) $reward->cmid));
        $this->assertTrue(
            $this->module_is_available_to($course, (int) $reward->cmid, (int) $student->id),
            'The student the original opened it for still sees it.'
        );
        $this->assertFalse(
            $this->module_is_available_to($course, (int) $reward->cmid, (int) $other->id),
            'Nobody else was let in.'
        );
    }

    /**
     * Discarding the copy leaves the activity the original keeps open exactly as it was. The
     * enable-activity action restores an activity's visibility snapshot when it is deleted; a copy
     * that carried the original's snapshot would hide, on deletion, the activity the original still
     * opens for its students. The copy carries no activities, so its deletion has nothing to restore.
     *
     * @covers ::duplicate
     */
    public function test_discarding_the_copy_leaves_the_activity_the_original_keeps_open_untouched(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->require_availability_user();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Born hidden: the original rule is what makes it visible.
        $reward = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'visible' => 0]);
        $ruleid = $this->rule_that_opens($course, (int) $reward->cmid, (int) $student->id);
        $before = $this->gate_of((int) $reward->cmid);
        $this->assertSame(1, $before['visible'], 'Sanity: the original opened the activity.');

        $newid = rule_duplicator::duplicate($ruleid, (int) $course->id, \context_course::instance($course->id));

        // The teacher discards the draft, through the same path the delete page takes.
        $copy = $DB->get_record('local_coursedynamicrules_rule', ['id' => $newid], '*', MUST_EXIST);
        (new rule($copy, []))->delete();

        $this->assertSame($before, $this->gate_of((int) $reward->cmid), 'The activity is exactly as the original left it.');
        $this->assertTrue(
            $this->module_is_available_to($course, (int) $reward->cmid, (int) $student->id),
            'The student the original opened it for still sees it.'
        );
        $this->assertSame(1, $DB->count_records('local_coursedynamicrules_action', ['ruleid' => $ruleid]));
        $this->assertSame(0, $DB->count_records('local_coursedynamicrules_rule', ['id' => $newid]));
        $this->assertDebuggingNotCalled();
    }
}
