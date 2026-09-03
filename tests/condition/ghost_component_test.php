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

use local_coursedynamicrules\condition\complete_activity\complete_activity_condition;
use local_coursedynamicrules\core\condition;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * A component whose target activity was deleted (a "ghost") must stay VISIBLE and manageable,
 * not vanish silently.
 *
 * KNOWN DEFECT (materialises MDL-INT-014, [Pendiente:fail], irreversible data-loss category):
 * today a condition whose course module was deleted returns an empty description, so it is not
 * rendered on the components page — it becomes invisible, unremovable from the UI, evaluates
 * false forever, and still counts toward completeness, letting a rule be sealed dead. This test
 * asserts the CORRECT behaviour (the ghost surfaces a warning description and keeps its id so it
 * can be deleted) and MUST FAIL until the visibility fix lands.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\complete_activity\complete_activity_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ghost_component_test extends \advanced_testcase {
    /**
     * MDL-INT-014: a condition whose activity was deleted still describes itself (with a warning)
     * and keeps its id, so the operator can see and remove it.
     */
    public function test_ghost_condition_stays_visible_and_manageable(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Rule with a ghost',
            'active' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $cm = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $record = (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'complete_activity',
            'params' => json_encode([]),
        ];
        $condition = new complete_activity_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'coursemodule' => $cm->cmid]);
        $storedid = $condition->get_id();

        // The activity the condition targets is deleted from the course.
        course_delete_module($cm->cmid);
        rebuild_course_cache($course->id, true);

        // Reload the condition exactly as the components page does.
        $stored = $DB->get_record(condition::TABLE, ['id' => $storedid], '*', MUST_EXIST);
        $ghost = new complete_activity_condition($stored, $course->id);

        $this->assertSame(
            $storedid,
            $ghost->get_id(),
            'The ghost keeps its id so the operator can delete it from the interface.'
        );
        $this->assertNotSame(
            '',
            trim($ghost->get_description()),
            'A component whose activity was deleted must describe itself with a warning instead of '
            . 'returning an empty description that hides its card from the listing.'
        );
    }
}
