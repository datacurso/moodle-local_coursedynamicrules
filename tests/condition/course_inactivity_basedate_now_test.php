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

namespace local_coursedynamicrules\condition;

use local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition;

/**
 * The "from now" base date of the course-inactivity condition must anchor on a STABLE moment
 * (the rule's activation), not on the instant of each evaluation.
 *
 * Materialises MDL-UNIT-011. Until 1.8.4 the base was recomputed as the current time on every
 * evaluation, so the milestone was always "right now": with a recurring interval the 6-hour window
 * was satisfied on every cron pass — an inactive student was notified every 6 hours instead of
 * once per interval — and with custom intervals every milestone landed in the future and the
 * condition never fired. The base is now the rule's activation moment (rule_lock::activation_time),
 * and these tests pin that anchor from both sides: too early must not fire, inside the window must.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_inactivity_basedate_now_test extends \advanced_testcase {
    /** @var int Activation moment used as the stable anchor. */
    private $activation;

    /** @var int Course id. */
    private $courseid;

    /** @var \stdClass The inactive student. */
    private $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->activation = strtotime('2025-01-01 09:00:00');
        $course = $this->getDataGenerator()->create_course();
        $this->courseid = $course->id;
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // The student never accesses the course: no user_lastaccess row is created, so every
        // evaluation below sees a genuinely inactive learner.
    }

    /**
     * Build a course_inactivity condition on a rule stamped as activated at $this->activation,
     * evaluated as if the clock were $now.
     *
     * @param array $params Condition params (intervaltype, timeintervals, intervalunit, basedatetype).
     * @param int $now The evaluation clock.
     * @return course_inactivity_condition
     */
    private function condition_at(array $params, int $now): course_inactivity_condition {
        global $DB;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->courseid,
            'name' => 'Inactivity rule',
            'active' => 1,
            'timeactivated' => $this->activation,
            'timecreated' => $this->activation,
            'timemodified' => $this->activation,
        ]);
        $record = (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'course_inactivity',
            'params' => json_encode($params),
            'lastexecutiontime' => null,
        ];
        return new course_inactivity_condition($record, $this->courseid, $now);
    }

    /**
     * MDL-UNIT-011: recurring interval with base "from now" must not fire before the first
     * interval elapses from the rule's activation.
     *
     * With the bug the base equals the evaluation clock, so the milestone is always "now" and the
     * inactive student matches on every pass. Correct: measured from activation, two days in is
     * long before the 7-day milestone, so the condition is NOT met.
     *
     * @covers ::evaluate
     */
    public function test_recurring_from_now_does_not_fire_before_the_first_interval(): void {
        $condition = $this->condition_at([
            'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
            'timeintervals' => '7',
            'intervalunit' => 'days',
            'basedatetype' => course_inactivity_condition::DATE_FROM_NOW,
        ], $this->activation + 2 * DAYSECS);

        $met = $condition->evaluate((object) [
            'courseid' => $this->courseid,
            'userid' => $this->student->id,
        ]);

        $this->assertFalse(
            $met,
            'An inactive student two days after activation must NOT match a 7-day recurring '
            . 'inactivity milestone; matching here is the "notify every 6 hours" defect.'
        );
    }

    /**
     * MDL-UNIT-011: a recurring "from now" rule must not fire on the first task run after activation.
     *
     * The two-day sample above sits in the dead zone between the anchor and the first milestone, so
     * it passes even when interval 0 is treated as a milestone. This sample sits INSIDE the window
     * that interval 0 would open - [activation, activation + CRON_INTERVAL_HOURS] - which is exactly
     * where the every-6-hours task lands right after an operator activates the rule. Nothing has
     * elapsed yet, so nobody can have been inactive "for 7 days": the condition must NOT be met.
     *
     * @covers ::evaluate
     */
    public function test_recurring_from_now_does_not_fire_on_the_first_run_after_activation(): void {
        $condition = $this->condition_at([
            'intervaltype' => course_inactivity_condition::INTERVAL_RECURRING,
            'timeintervals' => '7',
            'intervalunit' => 'days',
            'basedatetype' => course_inactivity_condition::DATE_FROM_NOW,
        ], $this->activation + 3 * HOURSECS);

        $met = $condition->evaluate((object) [
            'courseid' => $this->courseid,
            'userid' => $this->student->id,
        ]);

        $this->assertFalse(
            $met,
            'Three hours after activation no interval has elapsed; matching here would sweep the whole '
            . 'never-accessed cohort on day zero.'
        );
    }

    /**
     * MDL-UNIT-011: custom intervals with base "from now" must fire inside the window of a
     * milestone measured from the rule's activation.
     *
     * With the bug the base equals the evaluation clock, so every milestone is in the future and
     * the condition never fires. Correct: at the 7-day milestone (measured from activation, inside
     * the 6-hour window) the inactive student IS matched.
     *
     * @covers ::evaluate
     */
    public function test_custom_from_now_fires_inside_the_milestone_window(): void {
        $condition = $this->condition_at([
            'intervaltype' => course_inactivity_condition::INTERVAL_CUSTOM,
            'timeintervals' => '7,14',
            'intervalunit' => 'days',
            'basedatetype' => course_inactivity_condition::DATE_FROM_NOW,
        ], $this->activation + 7 * DAYSECS + 2 * HOURSECS);

        $met = $condition->evaluate((object) [
            'courseid' => $this->courseid,
            'userid' => $this->student->id,
        ]);

        $this->assertTrue(
            $met,
            'An inactive student inside the 7-day milestone window (measured from activation) must '
            . 'match; never matching here is the "rule never fires" defect.'
        );
    }
}
