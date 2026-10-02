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

namespace local_coursedynamicrules\task;

/**
 * The inactivity task reports what it did, like the two tasks beside it.
 *
 * The audit asked the enrolment-wide tasks to log their duration and how much they processed, and
 * to report a course larger than the configured page size. Two of the three do. This one walked
 * the same courses, in the same pages, and said nothing at all - so the one task an administrator
 * cannot watch is also the one that runs unattended for six hours between passes.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @covers      \local_coursedynamicrules\task\course_inactivity_task
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_inactivity_observability_test extends \advanced_testcase {
    /**
     * Seed an active inactivity rule that is due, with one notification action.
     *
     * @param \stdClass $course The course.
     * @return void
     */
    private function create_due_rule(\stdClass $course): void {
        global $DB;
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id, 'name' => 'Inactivity', 'description' => 'test', 'active' => 1,
            'lastexecutiontime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid, 'conditiontype' => 'course_inactivity',
            'params' => json_encode([
                'intervaltype' => 'custom', 'timeintervals' => '7',
                'intervalunit' => 'days', 'basedatetype' => 'coursestart',
            ]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid, 'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Inactivity', 'messagebody' => 'body',
                'primaryroleids' => [$studentroleid], 'copyroleids' => [],
            ]),
        ]);
    }

    /**
     * Run the task and return everything it printed.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new course_inactivity_task())->execute();
        return (string) ob_get_clean();
    }

    /**
     * A run reports the rules it evaluated, the users it processed and how long it took.
     *
     * @return void
     */
    public function test_a_run_reports_what_it_evaluated(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1, 'startdate' => time() - (7 * DAYSECS),
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        $this->create_due_rule($course);

        $this->redirectMessages();
        $output = $this->run_task();

        $this->assertStringContainsString('course_inactivity', $output, 'The run names the condition it evaluated.');
        $this->assertStringContainsString('1 active rules', $output);
        $this->assertStringContainsString('3 users', $output);
        $this->assertMatchesRegularExpression('/in \d+\.\d\ds\./', $output, 'The run reports its duration.');
    }

    /**
     * A course larger than the configured page size is named, as the sibling tasks name it.
     *
     * @return void
     */
    public function test_a_course_over_the_page_size_is_reported(): void {
        $this->resetAfterTest(true);
        set_config('taskbatchsize', 2, 'local_coursedynamicrules');
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => 1, 'startdate' => time() - (7 * DAYSECS),
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        $this->create_due_rule($course);

        $this->redirectMessages();
        $output = $this->run_task();

        $this->assertStringContainsString(
            'has 3 enrolled users (over batch threshold 2)',
            $output,
            'A course over the page size is reported, as the sibling tasks report it.'
        );
    }

    /**
     * A pass with no rules of this type says nothing at all.
     *
     * @return void
     */
    public function test_a_pass_with_no_rules_is_silent(): void {
        $this->resetAfterTest(true);
        $this->getDataGenerator()->create_course();

        $this->assertSame('', $this->run_task());
    }
}
