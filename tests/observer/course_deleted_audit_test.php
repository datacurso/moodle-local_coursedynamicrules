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

namespace local_coursedynamicrules\observer;

/**
 * Deleting a course takes every rule of that course with it, and that must leave a trace.
 *
 * Every other way a rule disappears emits the plugin's own rule_deleted event, so a log report can
 * answer what happened to it. This one path did not: the observer wiped the plugin's three tables
 * with bulk deletes and emitted nothing, so a whole course's automation could vanish with only
 * core's course_deleted in the log - which says a course was deleted, not which rules it carried.
 * That is the gap the audit's A09 names.
 *
 * The context is the catch. Core captures the course context object, deletes the context row, and
 * only then triggers the event (lib/moodlelib.php, delete_course()), so by the time the observer
 * runs there is no course context to instantiate. The event's own captured context is what the
 * plugin's events have to be built with.
 *
 * The observer is invoked here directly rather than by deleting a course, and that is not a
 * shortcut. Redirecting events is the only way to capture what this code emits, and redirection
 * switches every observer off: core\event\base::trigger() hands the event to the sink and returns
 * before it reaches the dispatcher. A test that deleted a course with a sink open would therefore
 * assert against an observer that never ran. The registration that connects the two lives in
 * db/events.php and is core's job to honour; what belongs here is what the observer does when it
 * is called.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @covers      \local_coursedynamicrules\observer\course_deleted
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_deleted_audit_test extends \advanced_testcase {
    /**
     * Insert a rule with one condition and one action.
     *
     * @param int $courseid The course.
     * @param string $name The rule name.
     * @return int The rule id.
     */
    private function create_rule(int $courseid, string $name): int {
        global $DB;
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid, 'name' => $name, 'description' => 'd', 'active' => 1,
            'lastexecutiontime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid, 'conditiontype' => 'no_course_access',
            'params' => json_encode(['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => 0]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid, 'actiontype' => 'sendnotification',
            'params' => json_encode(['messagesubject' => 's', 'messagebody' => 'b',
                'primaryroleids' => [], 'copyroleids' => []]),
        ]);
        return $ruleid;
    }

    /**
     * Fire the observer exactly as core would, and return what it emitted.
     *
     * @param \stdClass $course The course being deleted.
     * @return \core\event\base[] The events the observer triggered.
     */
    private function run_observer(\stdClass $course): array {
        $event = \core\event\course_deleted::create([
            'objectid' => $course->id,
            'context' => \context_course::instance($course->id),
            'other' => [
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'idnumber' => $course->idnumber,
            ],
        ]);

        $sink = $this->redirectEvents();
        course_deleted::observe($event);
        $events = $sink->get_events();
        $sink->close();

        return $events;
    }

    /**
     * Deleting a course emits one rule_deleted per rule it carried, and still removes the rows.
     *
     * @return void
     */
    public function test_deleting_a_course_leaves_one_deletion_event_per_rule(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $first = $this->create_rule($course->id, 'First');
        $second = $this->create_rule($course->id, 'Second');
        $survivor = $this->create_rule($other->id, 'Untouched');

        $events = $this->run_observer($course);

        $deleted = [];
        foreach ($events as $event) {
            if ($event instanceof \local_coursedynamicrules\event\rule_deleted) {
                $deleted[] = (int) $event->objectid;
            }
        }
        sort($deleted);
        $expected = [(int) $first, (int) $second];
        sort($expected);

        $this->assertSame($expected, $deleted, 'One rule_deleted per rule of the deleted course, and no other.');
        $this->assertFalse($DB->record_exists('local_coursedynamicrules_rule', ['courseid' => $course->id]));
        $this->assertTrue($DB->record_exists('local_coursedynamicrules_rule', ['id' => $survivor]));
        $this->assertFalse($DB->record_exists('local_coursedynamicrules_condition', ['ruleid' => $first]));
        $this->assertFalse($DB->record_exists('local_coursedynamicrules_action', ['ruleid' => $second]));
    }

    /**
     * The event carries the name of the rule that was lost, not just its id.
     *
     * An audit trail that says "rule 412 was deleted" answers nothing once the row is gone. The
     * snapshot is what makes the entry readable afterwards, and it is what a manual deletion
     * already attaches.
     *
     * @return void
     */
    public function test_the_event_carries_a_snapshot_of_the_lost_rule(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id, 'Weekly reminder');

        $events = $this->run_observer($course);

        foreach ($events as $event) {
            $ours = $event instanceof \local_coursedynamicrules\event\rule_deleted;
            if ($ours && (int) $event->objectid === (int) $ruleid) {
                $snapshot = $event->get_record_snapshot('local_coursedynamicrules_rule', $ruleid);
                $this->assertSame('Weekly reminder', $snapshot->name);
                return;
            }
        }

        $this->fail('No rule_deleted event was emitted for the rule the course carried.');
    }

    /**
     * A course with no rules of ours emits none of our events and fails nothing.
     *
     * @return void
     */
    public function test_a_course_without_rules_emits_nothing_of_ours(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $events = $this->run_observer($course);

        foreach ($events as $event) {
            $this->assertNotInstanceOf(\local_coursedynamicrules\event\rule_deleted::class, $event);
        }
    }
}
