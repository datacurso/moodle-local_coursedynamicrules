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
 * Tests for cross-course ownership validation.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\helper\ownership
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ownership_test extends \advanced_testcase {
    /** @var int Course A id. */
    private int $coursea;

    /** @var int Course B id (attacker's course). */
    private int $courseb;

    /** @var int Rule id in course A. */
    private int $ruleid;

    /** @var int Condition id in course A's rule. */
    private int $conditionid;

    /** @var int Action id in course A's rule. */
    private int $actionid;

    /**
     * Set up two courses and a rule (with a condition and an action) in course A.
     */
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);

        $this->coursea = $this->getDataGenerator()->create_course()->id;
        $this->courseb = $this->getDataGenerator()->create_course()->id;

        $this->ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->coursea,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->conditionid = $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $this->ruleid,
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => 1]),
        ]);
        $this->actionid = $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $this->ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode(['messagesubject' => 's']),
        ]);
    }

    /**
     * @covers ::get_rule
     */
    public function test_get_rule_returns_owned_rule(): void {
        $rule = ownership::get_rule($this->ruleid, $this->coursea);
        $this->assertEquals($this->ruleid, $rule->id);
    }

    /**
     * @covers ::get_rule
     */
    public function test_get_rule_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_rule($this->ruleid, $this->courseb);
    }

    /**
     * @covers ::rule_belongs_to_course
     */
    public function test_rule_belongs_to_course(): void {
        $this->assertTrue(ownership::rule_belongs_to_course($this->ruleid, $this->coursea));
        $this->assertFalse(ownership::rule_belongs_to_course($this->ruleid, $this->courseb));
    }

    /**
     * @covers ::get_condition
     */
    public function test_get_condition_returns_owned(): void {
        $condition = ownership::get_condition($this->conditionid, $this->coursea);
        $this->assertEquals($this->conditionid, $condition->id);
    }

    /**
     * @covers ::get_condition
     */
    public function test_get_condition_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_condition($this->conditionid, $this->courseb);
    }

    /**
     * @covers ::get_action
     */
    public function test_get_action_returns_owned(): void {
        $action = ownership::get_action($this->actionid, $this->coursea);
        $this->assertEquals($this->actionid, $action->id);
    }

    /**
     * @covers ::get_action
     */
    public function test_get_action_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_action($this->actionid, $this->courseb);
    }

    /**
     * A create (no submitted id) resolves to 0 so the caller inserts a new rule.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_resolve_writable_ruleid_returns_zero_for_create(): void {
        $this->assertSame(0, ownership::resolve_writable_ruleid(0, $this->coursea));
        $this->assertSame(0, ownership::resolve_writable_ruleid('', $this->coursea));
    }

    /**
     * An update targeting an owned rule resolves to that rule id.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_resolve_writable_ruleid_returns_owned_id(): void {
        $this->assertSame($this->ruleid, ownership::resolve_writable_ruleid($this->ruleid, $this->coursea));
    }

    /**
     * An update targeting a foreign course's rule (tampered hidden id) is rejected.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_resolve_writable_ruleid_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::resolve_writable_ruleid($this->ruleid, $this->courseb);
    }
}
