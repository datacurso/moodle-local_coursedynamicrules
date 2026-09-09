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

use local_coursedynamicrules\task\rule_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/completionlib.php');

/**
 * Completion and grading events must enqueue a single immediate rule evaluation each, carrying the
 * condition types the triggering event concerns.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\observer\course_module_completion_updated
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event_enqueue_test extends \advanced_testcase {
    /**
     * Fetch the queued rule_task adhoc tasks and their custom data.
     *
     * @return \stdClass[] Custom data objects of each queued rule_task.
     */
    private function queued_rule_tasks(): array {
        $out = [];
        foreach (\core\task\manager::get_adhoc_tasks(rule_task::class) as $task) {
            $out[] = $task->get_custom_data();
        }
        return $out;
    }

    /**
     * MDL-INT-003: completing an activity enqueues exactly one immediate rule evaluation carrying
     * the complete_activity condition type.
     */
    public function test_completing_an_activity_enqueues_one_evaluation(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('page', $page->cmid, $course->id, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $queued = $this->queued_rule_tasks();
        $this->assertCount(1, $queued, 'Completing an activity must enqueue exactly one rule evaluation.');
        $this->assertContains(
            'complete_activity',
            (array) $queued[0]->conditiontypes,
            'The queued evaluation must carry the completion condition type.'
        );
    }

    /**
     * MDL-INT-003: a duplicate completion event does not enqueue a second identical task while the
     * first is still pending.
     */
    public function test_a_duplicate_completion_event_does_not_enqueue_twice(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('page', $page->cmid, $course->id, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        // A redundant completion event for the same completion record.
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $this->assertCount(
            1,
            $this->queued_rule_tasks(),
            'An identical still-pending evaluation must not be enqueued a second time.'
        );
    }
}
