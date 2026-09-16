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
 * How often the enrolment-wide tasks run, and why that is the right number.
 *
 * The audit's low finding is titled "scheduled tasks with a high frequency", and two of these ran
 * every minute - 1440 passes a day each, over every enrolled user of every course with an active
 * rule. What made that unnecessary is not the cost of a pass, which the paged walk already bounded,
 * but the fact that the cadence never decided anything: each rule carries its own clock. The
 * no-access condition writes its next due time by adding its own period, and the not-completed one
 * compares against an expected completion date. So the task cadence only decides how long a rule
 * waits AFTER it is due.
 *
 * The finest unit either condition offers an operator is the hour (see the period selector on the
 * no-access form). A quarter of an hour of slack is therefore invisible against the smallest window
 * the product lets anyone configure, and it is ninety-six times fewer passes.
 *
 * Pinned here rather than left in db/tasks.php alone because it is a promise about product
 * behaviour - the delay between a rule falling due and acting on it - and someone tightening or
 * loosening it should have to say so.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @coversNothing
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_test extends \advanced_testcase {
    /**
     * The default schedule each task ships with, keyed by class name.
     *
     * @return array<string, \core\task\scheduled_task>
     */
    private function default_schedules(): array {
        $tasks = \core\task\manager::load_default_scheduled_tasks_for_component('local_coursedynamicrules');

        $bytask = [];
        foreach ($tasks as $task) {
            $bytask[get_class($task)] = $task;
        }

        return $bytask;
    }

    /**
     * Neither enrolment-wide task runs every minute any more.
     *
     * @return void
     */
    public function test_no_task_runs_every_minute(): void {
        $this->resetAfterTest(true);

        foreach ($this->default_schedules() as $classname => $task) {
            $this->assertNotSame(
                '*',
                $task->get_minute(),
                "{$classname} still runs every minute; the finding is about exactly that."
            );
        }
    }

    /**
     * The two tasks the audit names run four times an hour, on the hour's quarters.
     *
     * @return void
     */
    public function test_the_two_named_tasks_run_every_quarter_hour(): void {
        $this->resetAfterTest(true);
        $schedules = $this->default_schedules();

        foreach ([no_complete_activity_task::class, no_course_access_task::class] as $classname) {
            $this->assertArrayHasKey($classname, $schedules);
            $this->assertSame('*/15', $schedules[$classname]->get_minute(), $classname);
            $this->assertSame('*', $schedules[$classname]->get_hour(), $classname);
        }
    }

    /**
     * The inactivity task keeps its six-hour cadence, which the audit did not question.
     *
     * @return void
     */
    public function test_the_inactivity_task_is_unchanged(): void {
        $this->resetAfterTest(true);
        $schedules = $this->default_schedules();

        $this->assertSame('0', $schedules[course_inactivity_task::class]->get_minute());
        $this->assertSame('*/6', $schedules[course_inactivity_task::class]->get_hour());
    }

    /**
     * Every task ships enabled: a schedule nobody runs is not a schedule.
     *
     * @return void
     */
    public function test_every_task_ships_enabled(): void {
        $this->resetAfterTest(true);

        foreach ($this->default_schedules() as $classname => $task) {
            $this->assertFalse($task->get_disabled(), "{$classname} ships disabled.");
        }
    }
}
