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

use core_availability\tree;

/**
 * Tests for the enableactivity action robustness against deleted/changed modules.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\action\enableactivity\enableactivity_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enableactivity_action_test extends \advanced_testcase {
    /**
     * Set the user-restriction availability tree the action expects on a module.
     *
     * @param int $cmid Course module id.
     * @return void
     */
    private function set_user_restriction(int $cmid): void {
        global $DB;
        $tree = tree::get_root_json([(object) ['type' => 'user', 'userids' => []]], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * Build an enableactivity action for the given course modules.
     *
     * @param array $coursemodules Array of [id, visible, visibleoncoursepage].
     * @param int $courseid Course id.
     * @return enableactivity_action
     */
    private function create_action(array $coursemodules, int $courseid): enableactivity_action {
        $record = (object) [
            'ruleid' => 1,
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => $coursemodules]),
        ];
        return new enableactivity_action($record, $courseid);
    }

    /**
     * Normal case: the matched user is added to the module's user restriction.
     */
    public function test_execute_adds_user_to_restriction(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->set_user_restriction($page->cmid);

        $action = $this->create_action(
            [['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertContains($user->id, $availability->c[0]->userids);
        $this->assertDebuggingNotCalled();
    }

    /**
     * A deleted module must be skipped without a fatal error, and later modules still processed.
     */
    public function test_execute_skips_deleted_module_and_continues(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->set_user_restriction($page->cmid);

        $action = $this->create_action(
            [
                ['id' => 999999, 'visible' => 0, 'visibleoncoursepage' => 0],
                ['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1],
            ],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalled();
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertContains($user->id, $availability->c[0]->userids);
    }

    /**
     * A module whose availability was cleared must be skipped without corrupting it.
     */
    public function test_execute_skips_when_availability_null(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('course_modules', 'availability', null, ['id' => $page->cmid]);

        $action = $this->create_action(
            [['id' => $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]],
            $course->id
        );
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalled();
        $this->assertNull($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
    }

    /**
     * The rule/action must be deletable even when a referenced module no longer exists.
     */
    public function test_delete_succeeds_when_module_deleted(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $record = (object) [
            'ruleid' => 1,
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [['id' => 999999, 'visible' => 0, 'visibleoncoursepage' => 0]]]),
        ];
        $record->id = $DB->insert_record('local_coursedynamicrules_action', $record);

        $action = new enableactivity_action($record, $course->id);
        $action->delete();

        $this->assertFalse($DB->record_exists('local_coursedynamicrules_action', ['id' => $record->id]));
    }

    /**
     * The description must skip deleted modules without warnings.
     */
    public function test_get_description_skips_deleted_module(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $action = $this->create_action(
            [['id' => 999999, 'visible' => 0, 'visibleoncoursepage' => 0]],
            $course->id
        );

        $description = $action->get_description();

        $this->assertIsString($description);
        $this->assertDebuggingNotCalled();
    }
}
