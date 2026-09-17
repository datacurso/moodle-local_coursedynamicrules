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
     * Give the course a rule, which is what makes its events worth evaluating.
     *
     * The observers ask whether the course has any rule of this plugin before they queue anything,
     * so a course without one is a course where nothing needs to happen. These tests are about what
     * the observers do for a course that USES the plugin, and before that check existed their setup
     * could leave the rule out without noticing.
     *
     * @param int $courseid The course.
     * @param int $active Whether the rule is active; the check deliberately does not care.
     * @return int The rule id.
     */
    private function give_the_course_a_rule(int $courseid, int $active = 1): int {
        global $DB;
        return (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Rule',
            'active' => $active,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A course that does not use this plugin costs it nothing when its students are graded.
     *
     * The observers used to queue an evaluation for every module grade and every completion on the
     * site, whether or not the course had a single rule to evaluate. Each of those tasks loads the
     * course's rules, finds none and returns - so a teacher grading a batch of assignments in a
     * course that never heard of this plugin filled the adhoc queue with one no-op task per
     * submission. The queue is a shared, serial resource, which makes this a cost paid by every
     * other plugin's scheduled work too.
     *
     * The guard asks whether the course has any rule AT ALL, not whether it has an active one. A
     * narrower check would change behaviour rather than only remove waste: grade conditions are
     * evaluated on this path and on no other, so a rule activated while a task sat in the queue
     * would silently lose that evaluation.
     *
     * @return void
     */
    public function test_a_course_with_no_rules_enqueues_nothing(): void {
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

        $this->assertSame(
            [],
            $this->queued_rule_tasks(),
            'A course with no rules queued an evaluation that can only load no rules and return: on a '
                . 'site where most courses do not use this plugin, that is one wasted task per grade '
                . 'and per completion.'
        );
    }

    /**
     * A course that DOES have a rule still enqueues, even while that rule is inactive.
     *
     * The pin on the guard rather than on the waste. Grade and completion conditions are evaluated
     * from this path alone, so the check may only skip courses where nothing could ever match; a
     * course holding a rule somebody has not activated yet is not one of those, because the rule can
     * be activated between the event and the task running.
     *
     * @return void
     */
    public function test_a_course_whose_rule_is_not_active_still_enqueues(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->give_the_course_a_rule((int) $course->id, 0);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('page', $page->cmid, $course->id, false, MUST_EXIST);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $this->assertCount(
            1,
            $this->queued_rule_tasks(),
            'The guard skipped a course that holds a rule: activating it before the task runs would '
                . 'then lose this evaluation, and no scheduled task covers grade conditions.'
        );
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
        // The observer now asks whether the course has a rule before queueing anything, so a course
        // without one is not the subject of this test: what is under test is the wiring from the
        // event to the queue, in a course that actually uses the plugin.
        $this->give_the_course_a_rule((int) $course->id);
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
        // As above: de-duplication is only a question for a course whose events are worth queueing.
        $this->give_the_course_a_rule((int) $course->id);
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
