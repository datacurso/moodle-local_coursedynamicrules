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

namespace local_coursedynamicrules\condition\grade_in_activity;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * A grade condition survives the grade item's id being recreated (activity edit / course restore):
 * both the listing description and the evaluation resolve the stored threshold by the STABLE
 * itemnumber, not the volatile grade_item id that orphaned the condition before the fix.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\condition\grade_in_activity\grade_in_activity_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_in_activity_recreated_item_test extends \advanced_testcase {
    /**
     * Build a graded, auto-completion assignment and a grade_in_activity condition whose stored key
     * points at a grade_item id that no longer exists - the exact post-recreation state.
     *
     * @param int $threshold The "grade >= threshold" value to store.
     * @return array{0: grade_in_activity_condition, 1: \stdClass, 2: int} condition, course, cmid.
     */
    private function orphaned_condition(int $threshold): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'grade' => 100,
        ]);

        // A stored condition keyed by a grade_item id that does not exist (999999): this is what a
        // real row looks like after Moodle recreated the activity's grade item under a new id. The
        // stored entry carries NO itemnumber, so it is the legacy shape the reader must revive.
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Grade rule',
            'active' => 1,
            'timeactivated' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $params = [
            'cmid' => (int) $assign->cmid,
            'gradeitemsconditions' => [
                'gradegte_999999' => [
                    'gradeitem' => 999999,
                    'condition' => 'gradegte',
                    'value' => $threshold,
                ],
            ],
        ];
        $record = (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'grade_in_activity',
            'params' => json_encode($params),
        ];
        $record->id = (int) $DB->insert_record('local_coursedynamicrules_condition', $record);

        return [new grade_in_activity_condition($record, (int) $course->id), $course, (int) $assign->cmid];
    }

    /**
     * The listing description shows the configured threshold even though the stored key's grade item
     * id is gone - the reported symptom.
     */
    public function test_description_shows_the_threshold_after_the_grade_item_id_changed(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$condition] = $this->orphaned_condition(50);

        $description = $condition->get_description();

        $this->assertStringContainsString('50', $description, 'The configured threshold must appear on the card.');
    }

    /**
     * The condition still EVALUATES against the current grade item: a user at or above the threshold
     * satisfies it, one below does not. Before the fix both returned false - the rule was dead.
     */
    public function test_evaluation_still_works_after_the_grade_item_id_changed(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$condition, $course, $cmid] = $this->orphaned_condition(50);

        $passing = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $failing = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Grade the current (real) grade item of the assignment - a different id from the stored one.
        $cm = get_coursemodule_from_id('assign', $cmid, $course->id, false, MUST_EXIST);
        $gradeitem = \grade_item::fetch([
            'iteminstance' => $cm->instance,
            'itemmodule' => 'assign',
            'itemtype' => 'mod',
            'itemnumber' => 0,
        ]);
        $gradeitem->update_final_grade($passing->id, 80);
        $gradeitem->update_final_grade($failing->id, 20);

        $this->assertTrue(
            $condition->evaluate((object) ['courseid' => (int) $course->id, 'userid' => (int) $passing->id]),
            'A user at 80 must satisfy "grade >= 50" even though the stored key names a dead grade item id.'
        );
        $this->assertFalse(
            $condition->evaluate((object) ['courseid' => (int) $course->id, 'userid' => (int) $failing->id]),
            'A user at 20 must not satisfy "grade >= 50".'
        );
    }
}
