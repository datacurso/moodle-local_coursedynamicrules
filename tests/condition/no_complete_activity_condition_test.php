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

use local_coursedynamicrules\condition\no_complete_activity\no_complete_activity_condition;

/**
 * Tests for No Complete Activity condition.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\no_complete_activity\no_complete_activity_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_complete_activity_condition_test extends \advanced_testcase {
    /** @var int Timestamp in the past (expected completion date already passed) */
    private int $pastdate;

    /** @var int Timestamp in the future (expected completion date not yet passed) */
    private int $futuredate;

    /**
     * Test setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->pastdate   = strtotime('-7 days');
        $this->futuredate = strtotime('+7 days');
    }

    /**
     * Build a condition record pointing at the given cm and expected completion date.
     *
     * @param int $cmid Course-module ID.
     * @param int $expectedcompletiondate Unix timestamp.
     * @return no_complete_activity_condition
     */
    private function create_condition(int $cmid, int $expectedcompletiondate): no_complete_activity_condition {
        $record = (object) [
            'ruleid'        => 1,
            'conditiontype' => 'no_complete_activity',
            'params'        => json_encode([
                'cmid'                   => $cmid,
                'expectedcompletiondate' => $expectedcompletiondate,
            ]),
        ];

        return new no_complete_activity_condition($record);
    }

    /**
     * When the expected completion date is in the future the condition must not
     * fire regardless of the user's completion state.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_when_deadline_not_reached(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC]
        );

        $condition = $this->create_condition($assign->cmid, $this->futuredate);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result, 'Condition must not fire before the expected completion date.');
    }

    /**
     * Regression test: activity has completion tracking disabled.
     * get_data() returns completionstate = NULL via a RIGHT JOIN on
     * course_modules_viewed when there is no course_modules_completion row.
     * evaluate() must handle the null gracefully and return false (cannot
     * determine incomplete status without tracking).
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_when_completion_tracking_disabled(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create activity WITHOUT completion tracking (COMPLETION_TRACKING_NONE = 0).
        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_NONE]
        );

        // Simulate the user having visited the activity so a course_modules_viewed
        // row exists — this triggers the RIGHT JOIN path that yields completionstate = NULL.
        $DB->insert_record('course_modules_viewed', (object) [
            'coursemoduleid' => $assign->cmid,
            'userid'         => $user->id,
            'timecreated'    => time(),
        ]);

        $condition = $this->create_condition($assign->cmid, $this->pastdate);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse(
            $result,
            'Condition must return false when completion tracking is disabled (completionstate is null).'
        );
    }

    /**
     * Activity is not completed and deadline has passed: condition fires.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_true_when_not_completed_and_deadline_passed(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        // User has NOT marked the activity complete — no completion record.
        $condition = $this->create_condition($assign->cmid, $this->pastdate);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertTrue($result, 'Condition must fire when the activity is not completed and the deadline has passed.');
    }

    /**
     * Activity is completed (COMPLETION_COMPLETE): condition must not fire.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_when_activity_is_completed(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        // Mark the activity as completed.
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $assign->cmid,
            'userid'         => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified'   => time(),
        ]);

        $condition = $this->create_condition($assign->cmid, $this->pastdate);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result, 'Condition must not fire when the activity is already completed.');
    }

    /**
     * Activity is completed with pass grade (COMPLETION_COMPLETE_PASS): condition must not fire.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_when_activity_completed_with_pass(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        // Mark the activity as completed with passing grade.
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $assign->cmid,
            'userid'         => $user->id,
            'completionstate' => COMPLETION_COMPLETE_PASS,
            'timemodified'   => time(),
        ]);

        $condition = $this->create_condition($assign->cmid, $this->pastdate);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result, 'Condition must not fire when the activity is completed with a pass grade.');
    }

    /**
     * Activity module is being deleted: condition must not fire.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_when_cm_deletion_in_progress(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $assign = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        // Flag the course module as being deleted and flush the modinfo cache so
        // get_fast_modinfo() picks up the updated field.
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id, true);

        $condition = $this->create_condition($assign->cmid, $this->pastdate);
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result, 'Condition must not fire when the course module is being deleted.');
    }

    /**
     * A missing course module must be rejected at save time, never persisted as cmid 0.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_rejects_missing_coursemodule(): void {
        global $DB;

        $record = (object) ['ruleid' => 1, 'conditiontype' => 'no_complete_activity', 'params' => json_encode([])];
        $condition = new no_complete_activity_condition($record, 1);

        $formdata = (object) ['ruleid' => 1, 'coursemodule' => 0, 'expectedcompletiondate' => $this->futuredate];

        try {
            $condition->save_condition($formdata);
            $this->fail('Expected invalid_parameter_exception for missing course module');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition'));
        }
    }

    /**
     * A stale cmid must not raise warnings in the description; it returns an empty string.
     *
     * @covers ::get_description
     */
    public function test_get_description_empty_without_warning_for_stale_cmid(): void {
        $course = $this->getDataGenerator()->create_course();

        $record = (object) [
            'ruleid' => 1,
            'conditiontype' => 'no_complete_activity',
            'params' => json_encode(['cmid' => 999999, 'expectedcompletiondate' => $this->futuredate]),
        ];
        $condition = new no_complete_activity_condition($record, $course->id);

        $description = $condition->get_description();

        $this->assertSame('', $description);
        $this->assertDebuggingNotCalled();
    }
}
