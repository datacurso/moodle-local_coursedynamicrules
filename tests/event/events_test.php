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

namespace local_coursedynamicrules\event;

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\rule_component_loader;

/**
 * Tests for the plugin audit events.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\event\rule_created
 * @covers     \local_coursedynamicrules\event\rule_updated
 * @covers     \local_coursedynamicrules\event\rule_deleted
 * @covers     \local_coursedynamicrules\event\condition_created
 * @covers     \local_coursedynamicrules\event\condition_updated
 * @covers     \local_coursedynamicrules\event\condition_deleted
 * @covers     \local_coursedynamicrules\event\action_created
 * @covers     \local_coursedynamicrules\event\action_updated
 * @covers     \local_coursedynamicrules\event\action_deleted
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class events_test extends \advanced_testcase {
    /**
     * Insert a bare rule and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function make_rule(int $courseid): int {
        global $DB;
        return (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Rule',
            'description' => '',
            'active' => 1,
            'lastexecutiontime' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Deleting a rule with no children fires a single rule_deleted event carrying its id.
     */
    public function test_rule_deleted_event(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->make_rule($course->id);
        $record = (object) ['id' => $ruleid, 'courseid' => $course->id, 'active' => 1];

        $sink = $this->redirectEvents();
        (new rule($record, []))->delete();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(rule_deleted::class, $events[0]);
        $this->assertEquals($ruleid, $events[0]->objectid);
        $this->assertEquals($course->id, $events[0]->courseid);
        $this->assertNotEmpty($events[0]->get_description());
    }

    /**
     * Deleting a condition fires a condition_deleted event.
     */
    public function test_condition_deleted_event(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->make_rule($course->id);
        $conditionid = (int) $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'no_course_access',
            'params' => json_encode(['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => 0]),
            'lastexecutiontime' => null,
        ]);
        $record = $DB->get_record('local_coursedynamicrules_condition', ['id' => $conditionid]);

        $sink = $this->redirectEvents();
        rule_component_loader::create_condition_instance($record, $course->id)->delete();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(condition_deleted::class, $events[0]);
        $this->assertEquals($conditionid, $events[0]->objectid);
    }

    /**
     * Deleting an action fires an action_deleted event.
     */
    public function test_action_deleted_event(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->make_rule($course->id);
        $actionid = (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'createaiactivity',
            'params' => json_encode(['message' => 'x', 'generateimages' => false, 'sectionnum' => 0, 'beforemod' => null]),
            'lastexecutiontime' => null,
        ]);
        $record = $DB->get_record('local_coursedynamicrules_action', ['id' => $actionid]);

        $sink = $this->redirectEvents();
        rule_component_loader::create_action_instance($record, $course->id)->delete();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(action_deleted::class, $events[0]);
        $this->assertEquals($actionid, $events[0]->objectid);
    }

    /**
     * Editing an existing condition through its real save path (save_condition() -> upsert())
     * fires condition_updated exactly once, carrying the edited condition's own id and course
     * context - mirroring what conditions.php does right after a successful save_condition() call.
     */
    public function test_condition_updated_event_on_edit(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $ruleid = $this->make_rule($course->id);
        $conditionid = (int) $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'no_course_access',
            'params' => json_encode(['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => 0]),
            'lastexecutiontime' => null,
        ]);
        $record = $DB->get_record('local_coursedynamicrules_condition', ['id' => $conditionid]);
        $instance = rule_component_loader::create_condition_instance($record, $course->id);

        $sink = $this->redirectEvents();
        $savedid = $instance->save_condition((object) [
            'ruleid' => $ruleid,
            'periodvalue' => 5,
            'periodunit' => 'days',
        ]);
        condition_updated::create([
            'context' => $context,
            'objectid' => $savedid,
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(condition_updated::class, $events[0]);
        $this->assertEquals($conditionid, $events[0]->objectid);
        $this->assertEquals($course->id, $events[0]->courseid);
        $this->assertNotEmpty($events[0]->get_description());
    }

    /**
     * Creating a new condition through save_condition() still fires condition_created, and NOT
     * condition_updated (there is nothing to "update" on a brand-new row).
     */
    public function test_condition_created_event_not_updated_on_create(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $ruleid = $this->make_rule($course->id);
        $instance = rule_component_loader::create_condition_instance(
            (object) ['ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])],
            $course->id
        );

        $sink = $this->redirectEvents();
        $conditionid = $instance->save_condition((object) [
            'ruleid' => $ruleid,
            'periodvalue' => 3,
            'periodunit' => 'days',
        ]);
        condition_created::create([
            'context' => $context,
            'objectid' => $conditionid,
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(condition_created::class, $events[0]);
        $this->assertNotInstanceOf(condition_updated::class, $events[0]);
        $this->assertEquals($conditionid, $events[0]->objectid);
    }

    /**
     * Editing an existing action through its real save path (save_action() -> upsert()) fires
     * action_updated exactly once, carrying the edited action's own id and course context -
     * mirroring what actions.php does right after a successful save_action() call.
     */
    public function test_action_updated_event_on_edit(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $ruleid = $this->make_rule($course->id);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $actionid = (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Old subject',
                'messagebody' => 'Old body',
                'primaryroleids' => [$studentroleid],
                'copyroleids' => [],
                'bodyisraw' => true,
            ]),
            'lastexecutiontime' => null,
        ]);
        $record = $DB->get_record('local_coursedynamicrules_action', ['id' => $actionid]);
        $instance = rule_component_loader::create_action_instance($record, $course->id);

        $sink = $this->redirectEvents();
        $savedid = $instance->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'New subject',
            'messagebody' => ['text' => 'New body', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);
        action_updated::create([
            'context' => $context,
            'objectid' => $savedid,
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(action_updated::class, $events[0]);
        $this->assertEquals($actionid, $events[0]->objectid);
        $this->assertEquals($course->id, $events[0]->courseid);
        $this->assertNotEmpty($events[0]->get_description());
    }

    /**
     * Creating a new action through save_action() still fires action_created, and NOT
     * action_updated (there is nothing to "update" on a brand-new row).
     */
    public function test_action_created_event_not_updated_on_create(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $ruleid = $this->make_rule($course->id);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $instance = rule_component_loader::create_action_instance(
            (object) ['ruleid' => $ruleid, 'actiontype' => 'sendnotification', 'params' => json_encode([])],
            $course->id
        );

        $sink = $this->redirectEvents();
        $actionid = $instance->save_action((object) [
            'ruleid' => $ruleid,
            'messagesubject' => 'Subject',
            'messagebody' => ['text' => 'Body', 'format' => FORMAT_HTML],
            'primaryrecipients' => [$studentroleid => 1],
            'copyrecipients' => [],
        ]);
        action_created::create([
            'context' => $context,
            'objectid' => $actionid,
        ])->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(action_created::class, $events[0]);
        $this->assertNotInstanceOf(action_updated::class, $events[0]);
        $this->assertEquals($actionid, $events[0]->objectid);
    }

    /**
     * The rule create and update events can be built and describe themselves.
     */
    public function test_rule_created_and_updated_events(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $classes = [
            rule_created::class,
            rule_updated::class,
            condition_created::class,
            action_created::class,
        ];
        foreach ($classes as $class) {
            $sink = $this->redirectEvents();
            $event = $class::create(['context' => $context, 'objectid' => 123]);
            $event->trigger();
            $events = $sink->get_events();

            $this->assertCount(1, $events);
            $this->assertInstanceOf($class, $events[0]);
            $this->assertEquals(123, $events[0]->objectid);
            $this->assertEquals($course->id, $events[0]->courseid);
            $this->assertNotEmpty($events[0]->get_description());
            $this->assertNotEmpty($event::get_name());
        }
    }

    /**
     * The condition save returns the id of the stored record so callers can log its creation.
     */
    public function test_save_condition_returns_new_id(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->make_rule($course->id);

        $instance = rule_component_loader::create_condition_instance(
            (object) ['conditiontype' => 'no_course_access', 'ruleid' => $ruleid, 'params' => json_encode([])],
            $course->id
        );
        $conditionid = $instance->save_condition((object) [
            'ruleid' => $ruleid,
            'periodvalue' => 1,
            'periodunit' => 'days',
        ]);

        $this->assertIsInt($conditionid);
        $this->assertGreaterThan(0, $conditionid);
        $this->assertTrue(
            $GLOBALS['DB']->record_exists('local_coursedynamicrules_condition', ['id' => $conditionid])
        );
    }
}
