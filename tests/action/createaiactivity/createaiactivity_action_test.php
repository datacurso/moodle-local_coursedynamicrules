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

/**
 * Tests for create AI activity action.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\action\createaiactivity\createaiactivity_action
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
     * Test description shows image generation status and the module the activity is inserted before.
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
