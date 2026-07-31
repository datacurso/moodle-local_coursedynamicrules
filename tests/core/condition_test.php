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

namespace local_coursedynamicrules\core;

/**
 * Tests for the condition base class upsert() helper.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\core\condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_test extends \advanced_testcase {
    /**
     * Load the stub_condition fixture used to exercise the base upsert() helper.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../fixtures/stub_condition.php');
    }

    /** @var int Course id. */
    private int $courseid;

    /** @var int Rule id belonging to the course. */
    private int $ruleid;

    /**
     * Create a course and a rule to attach stub conditions to.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);

        $this->courseid = $this->getDataGenerator()->create_course()->id;
        $this->ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * @covers ::upsert
     */
    public function test_upsert_inserts_a_new_row_without_id(): void {
        global $DB;

        $stub = $this->make_stub();

        $id = $stub->test_upsert(['foo' => 'bar'], (object) ['ruleid' => $this->ruleid]);

        $this->assertEquals(1, $DB->count_records(condition::TABLE));
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(['foo' => 'bar'], (array) json_decode($stored->params));
        $this->assertSame($id, $stub->get_id());
    }

    /**
     * @covers ::upsert
     */
    public function test_upsert_updates_existing_row_in_place(): void {
        global $DB;

        $stub = $this->make_stub();
        $id = $stub->test_upsert(['foo' => 'bar'], (object) ['ruleid' => $this->ruleid]);

        $updatedid = $stub->test_upsert(['foo' => 'baz'], (object) ['ruleid' => $this->ruleid]);

        $this->assertSame($id, $updatedid);
        $this->assertEquals(1, $DB->count_records(condition::TABLE));
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(['foo' => 'baz'], (array) json_decode($stored->params));
    }

    /**
     * @covers ::upsert
     */
    public function test_upsert_update_does_not_touch_runtime_fields(): void {
        global $DB;

        $stub = $this->make_stub();
        $id = $stub->test_upsert(['foo' => 'bar'], (object) ['ruleid' => $this->ruleid]);
        $DB->set_field(condition::TABLE, 'lastexecutiontime', 12345, ['id' => $id]);

        $stub->test_upsert(['foo' => 'updated'], (object) ['ruleid' => $this->ruleid]);

        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(12345, $stored->lastexecutiontime);
        $this->assertEquals($this->ruleid, $stored->ruleid);
        $this->assertEquals('stub', $stored->conditiontype);
    }

    /**
     * Characterises the failure mode closed by passing $courseid to
     * rule_component_loader::create_condition_instance() on the create branch (conditions.php): a
     * condition constructed without a courseid cannot resolve its ruleid's owning course on insert, so
     * upsert() must reject it loudly instead of silently inserting an orphaned/unscoped row.
     *
     * @covers ::upsert
     */
    public function test_upsert_insert_without_courseid_throws_missing_record(): void {
        $record = (object) [
            'id' => null,
            'ruleid' => $this->ruleid,
            'conditiontype' => 'stub',
            'params' => json_encode([]),
        ];
        $stub = new stub_condition($record, null);

        $this->expectException(\dml_missing_record_exception::class);
        $stub->test_upsert(['foo' => 'bar'], (object) ['ruleid' => $this->ruleid]);
    }

    /**
     * The create-branch seed record conditions.php builds before a ruleid is known used to omit
     * the `ruleid` key entirely, and set_data() read `$record->ruleid` unconditionally, emitting an
     * "Undefined property" warning on every create-form render (FIX2-8). set_data() must default a
     * missing ruleid to null instead.
     *
     * @covers ::set_data
     */
    public function test_set_data_defaults_missing_ruleid_to_null_without_warning(): void {
        $record = (object) [
            'id' => null,
            'conditiontype' => 'stub',
            'params' => json_encode([]),
        ];

        $stub = new stub_condition($record, $this->courseid);

        $property = new \ReflectionProperty(condition::class, 'ruleid');
        $property->setAccessible(true);
        $this->assertNull($property->getValue($stub));
    }

    /**
     * Build a fresh stub condition attached to the test course, no stored id yet.
     *
     * @return stub_condition
     */
    private function make_stub(): stub_condition {
        $record = (object) [
            'id' => null,
            'ruleid' => $this->ruleid,
            'conditiontype' => 'stub',
            'params' => json_encode([]),
        ];
        return new stub_condition($record, $this->courseid);
    }
}
