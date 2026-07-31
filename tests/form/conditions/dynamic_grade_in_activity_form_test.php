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

namespace local_coursedynamicrules\form\conditions;

/**
 * Tests for the grade_in_activity dynamic sub-form's server-side preload (D5: hidden fields on the
 * outer form are the DOM source of truth read by the AMD module's initial dynamicForm.load() call;
 * no gradeitems payload crosses the PHP->JS bridge via js_call_amd).
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\form\conditions\dynamic_grade_in_activity_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dynamic_grade_in_activity_form_test extends \advanced_testcase {
    /**
     * Build a course with a graded quiz (automatic completion + require grade).
     *
     * @return array [stdClass $course, cm_info-like $cm, int $gradeitemid]
     */
    private function create_graded_quiz(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'grade' => 10,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $gradeitem = \grade_item::fetch(['iteminstance' => $quiz->id, 'itemmodule' => 'quiz', 'itemtype' => 'mod']);

        return [$course, $cm, $gradeitem->id];
    }

    /**
     * Export the form's current (default) values, including elements nested inside addGroup()
     * groups. The grade condition elements (enablegradegte_X, gradegte_X, ...) are grouped with
     * appendName=false, so their names are NOT registered in HTML_QuickForm's top-level element
     * index and getElement() cannot reach them directly — exportValues() recurses into groups and
     * is the documented way to read their values.
     *
     * @param \moodleform $form Form instance.
     * @return array
     */
    private function export_values($form): array {
        $reflection = new \ReflectionClass($form);
        $property = $reflection->getProperty('_form');
        $property->setAccessible(true);
        $mform = $property->getValue($form);

        return $mform->exportValues();
    }

    /**
     * set_data_for_dynamic_submission() must decode the ajax-supplied 'gradeitems' JSON and
     * pre-populate the matching enable{cond}_{gid} checkbox and {cond}_{gid} value input.
     *
     * @covers ::set_data_for_dynamic_submission
     */
    public function test_set_data_for_dynamic_submission_preloads_stored_gradeitems(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid] = $this->create_graded_quiz();

        $ajaxformdata = [
            'courseid' => $course->id,
            'coursemodule' => $cm->id,
            'gradeitems' => json_encode([
                'gradelt_' . $gradeitemid => [
                    'gradeitem' => $gradeitemid,
                    'condition' => 'gradelt',
                    'value' => 8.5,
                ],
            ]),
        ];

        $form = new dynamic_grade_in_activity_form(null, null, 'post', '', null, true, $ajaxformdata);
        $form->set_data_for_dynamic_submission();

        $values = $this->export_values($form);

        $this->assertEquals(1, $values['enablegradelt_' . $gradeitemid]);
        $this->assertEquals(8.5, $values['gradelt_' . $gradeitemid]);

        // The companion "greater than or equal" threshold was not stored: it must stay disabled,
        // not spuriously enabled by the preload.
        $this->assertEquals(0, $values['enablegradegte_' . $gradeitemid]);
    }

    /**
     * An empty gradeitems payload (the "create" flow's blank state) must leave the form untouched:
     * no threshold checkbox is forced on.
     *
     * @covers ::set_data_for_dynamic_submission
     */
    public function test_set_data_for_dynamic_submission_handles_empty_gradeitems(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid] = $this->create_graded_quiz();

        $ajaxformdata = [
            'courseid' => $course->id,
            'coursemodule' => $cm->id,
            'gradeitems' => '{}',
        ];

        $form = new dynamic_grade_in_activity_form(null, null, 'post', '', null, true, $ajaxformdata);
        $form->set_data_for_dynamic_submission();

        $values = $this->export_values($form);

        $this->assertEquals(0, $values['enablegradelt_' . $gradeitemid]);
        $this->assertEquals(0, $values['enablegradegte_' . $gradeitemid]);
    }

    /**
     * A stored entry marked disabled:true (the AMD rebuild serialises disabled:true entries for a
     * deliberately unchecked threshold) must stay unchecked on a failed-validation redisplay - not
     * be force re-enabled just because it still carries a stored value (FIX2-7).
     *
     * @covers ::set_data_for_dynamic_submission
     */
    public function test_set_data_for_dynamic_submission_skips_disabled_entries(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid] = $this->create_graded_quiz();

        $ajaxformdata = [
            'courseid' => $course->id,
            'coursemodule' => $cm->id,
            'gradeitems' => json_encode([
                'gradelt_' . $gradeitemid => [
                    'gradeitem' => $gradeitemid,
                    'condition' => 'gradelt',
                    'value' => 8.5,
                    'disabled' => true,
                ],
            ]),
        ];

        $form = new dynamic_grade_in_activity_form(null, null, 'post', '', null, true, $ajaxformdata);
        $form->set_data_for_dynamic_submission();

        $values = $this->export_values($form);

        $this->assertEquals(0, $values['enablegradelt_' . $gradeitemid]);
    }

    /**
     * A stored 'coursemodule' whose activity is no longer eligible (deleted, or no longer
     * completion/grade tracked) must not fatal: definition() indexed $filteredcms[$cmid] directly
     * and then dereferenced the result's ->id unconditionally, so a stale/foreign cmid crashed the
     * whole edit page instead of falling back to the first eligible activity (G5).
     *
     * @covers ::definition
     */
    public function test_definition_falls_back_when_stored_cmid_no_longer_eligible(): void {
        $this->resetAfterTest(true);

        [$course, $cm, $gradeitemid] = $this->create_graded_quiz();

        $stalecmid = $cm->id + 999999;

        $ajaxformdata = [
            'courseid' => $course->id,
            'coursemodule' => $stalecmid,
        ];

        $form = new dynamic_grade_in_activity_form(null, null, 'post', '', null, true, $ajaxformdata);

        $this->assertDebuggingNotCalled();
        $values = $this->export_values($form);
        $this->assertEquals($cm->id, $values['coursemodule']);
    }
}
