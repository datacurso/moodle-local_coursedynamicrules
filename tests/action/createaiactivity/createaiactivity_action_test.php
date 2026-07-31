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

namespace local_coursedynamicrules\action\createaiactivity;

use local_coursedynamicrules\core\action;

/**
 * Tests for create AI activity action.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\action\createaiactivity\createaiactivity_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class createaiactivity_action_test extends \advanced_testcase {
    /**
     * Build an action instance from raw params.
     *
     * @param array $params Action params.
     * @param int $courseid Course id.
     * @return createaiactivity_action
     */
    private function create_test_action(array $params, int $courseid): createaiactivity_action {
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode($params),
        ];

        return new createaiactivity_action($record, $courseid);
    }

    /**
     * Insert a rule row belonging to the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A round-trip create -> edit must persist exactly one row, update the mutated field, leave
     * lastexecutiontime untouched, and map an unselected beforemod (null) to 0.
     *
     * @covers ::save_action
     */
    public function test_save_action_round_trip_persists_single_row_with_beforemod_null_to_zero(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) [
            'id' => null, 'ruleid' => $ruleid, 'actiontype' => 'createaiactivity', 'params' => json_encode([]),
        ];
        $action = new createaiactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'message' => 'Original prompt',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ]);

        $id = $action->get_id();
        $DB->set_field(action::TABLE, 'lastexecutiontime', 55555, ['id' => $id]);

        $stored = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $storedparams = json_decode($stored->params);

        $reflection = new \ReflectionClass(\local_coursedynamicrules\form\actions\createaiactivity_form::class);
        $forminstance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);
        $defaults = $method->invoke($forminstance, $storedparams);
        $this->assertSame(0, $defaults['beforemod']);

        $editaction = new createaiactivity_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'message' => 'Updated prompt',
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => null,
        ]);

        $this->assertEquals($id, $editaction->get_id());
        $this->assertEquals(1, $DB->count_records(action::TABLE, ['ruleid' => $ruleid]));

        $final = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(55555, $final->lastexecutiontime);
        $finalparams = json_decode($final->params);
        $this->assertEquals('Updated prompt', $finalparams->message);
        $this->assertTrue($finalparams->generateimages);
        $this->assertNull($finalparams->beforemod);
    }

    /**
     * Test description shows image generation status and the module the activity is inserted before.
     *
     * @covers ::get_description
     */
    public function test_get_description_shows_generateimages_and_beforemod(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Reference assignment',
        ]);

        $prompt = 'Create a reinforcement activity about polynomial factorization with several'
            . ' worked examples and practice questions for struggling students';

        $action = $this->create_test_action([
            'message' => $prompt,
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => $assign->cmid,
        ], $course->id);

        $description = $action->get_description();

        $this->assertStringContainsString(get_section_name($course, 0), $description);
        $this->assertStringContainsString(shorten_text($prompt, 80), $description);
        $this->assertStringContainsString(get_string('yes'), $description);
        $this->assertStringContainsString('Reference assignment', $description);
    }

    /**
     * Test description without image generation and without insert position.
     *
     * @covers ::get_description
     */
    public function test_get_description_without_images_or_beforemod(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $action = $this->create_test_action([
            'message' => 'Short prompt',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $description = $action->get_description();

        $generateimagesstring = get_string(
            'createaiactivity_description_generateimages',
            'local_coursedynamicrules',
            get_string('no')
        );

        $this->assertStringContainsString($generateimagesstring, $description);
        $this->assertStringNotContainsString(
            get_string('createaiactivity_description_beforemod', 'local_coursedynamicrules', ''),
            $description
        );
    }

    /**
     * Test description does not fail when beforemod points to a deleted module.
     *
     * @covers ::get_description
     */
    public function test_get_description_with_stale_beforemod(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $action = $this->create_test_action([
            'message' => 'Short prompt',
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => 999999,
        ], $course->id);

        $description = $action->get_description();

        $this->assertStringContainsString(get_string('yes'), $description);
        $this->assertStringNotContainsString('999999', $description);
    }
}
