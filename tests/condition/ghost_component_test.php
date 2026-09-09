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
use local_coursedynamicrules\helper\component_renderer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * A component whose target activity was deleted (a "ghost") must stay VISIBLE and manageable,
 * not vanish silently.
 *
 * Materialises MDL-INT-014. Until 1.8.4 a condition whose course module was deleted returned an
 * empty description, so it was not rendered on the components page — it became invisible,
 * unremovable from the UI, evaluated false forever, and still counted toward completeness, letting
 * a rule be sealed dead. The ghost now surfaces the shared missing-activity warning and keeps its
 * id, so it can be seen and deleted. Completeness still counts it, by design: that decision is
 * documented in CHANGES.md.
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
     *
     * Four facts, in the order the operator meets them: the row survives with its id (so the delete
     * endpoint can address it), it still evaluates as not met (a ghost must never fire), it describes
     * itself (the description is what the components page and the rules list key their rendering
     * on), and the listing renderer actually emits it - an empty description would drop the card and
     * with it the only trash can the operator has.
     *
     * @covers ::get_description
     * @covers ::evaluate
     * @covers \local_coursedynamicrules\helper\component_renderer::descriptions_html
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
        $storedid = (int) $condition->get_id();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Sanity: while the activity exists the condition describes it.
        $this->assertStringContainsString(
            $cm->name,
            $condition->get_description(),
            'Sanity: a live condition names its activity.'
        );

        // The activity the condition targets is deleted from the course.
        course_delete_module($cm->cmid);
        rebuild_course_cache($course->id, true);

        // Reload the condition exactly as the components page does.
        $stored = $DB->get_record(condition::TABLE, ['id' => $storedid], '*', MUST_EXIST);
        $ghost = new complete_activity_condition($stored, $course->id);

        // The row survives - DB ids come back as strings, the identity is the number.
        $this->assertSame(
            $storedid,
            (int) $ghost->get_id(),
            'The ghost keeps its id so the operator can delete it from the interface.'
        );

        // A ghost must never fire: nothing can be completed in an activity that is gone.
        $this->assertFalse(
            $ghost->evaluate((object) ['courseid' => $course->id, 'userid' => $student->id]),
            'A condition whose activity was deleted must not be met.'
        );

        // The defect: an empty description made the card vanish from every listing. The ghost
        // describes itself with the shared warning - exactly that string, not any non-empty text.
        $description = $ghost->get_description();
        $this->assertSame(
            get_string('componenttargetmissing', 'local_coursedynamicrules'),
            $description,
            'A component whose activity was deleted must describe itself with the missing-activity '
            . 'warning instead of returning an empty description that hides its card from the listing.'
        );
        $this->assertDebuggingNotCalled();

        // The listing renderer is what the rules list uses: the ghost must come out of it.
        $this->assertStringContainsString(
            s($description),
            component_renderer::descriptions_html([$ghost]),
            'The rules list must render the ghost, warning included, instead of skipping it.'
        );
    }
}
