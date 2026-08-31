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
}
