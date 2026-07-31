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
        return $this->get_mform($form)->exportValues();
    }

    /**
     * Reflect out the protected \MoodleQuickForm instance backing a moodleform/dynamic_form.
     *
     * Needed to assert element *existence* (via elementExists()) for the grade condition groups:
     * exportValues() only surfaces a grouped value element (gradegte_X, gradelt_X) once it has a
     * non-null value (default or submitted) - see HTML_QuickForm_group::exportValue() and
     * HTML_QuickForm_element::exportValue(), which return null for an untouched text/select
     * element and are then dropped by the group instead of exported as null. A bare definition()
     * call (no set_data_for_dynamic_submission()) never sets that default, so gradegte_X/gradelt_X
     * are legitimately absent from exportValues() even though the elements exist in the form. The
     * enclosing group ('gradegtegroup_X'/'gradeltgroup_X'), however, IS registered in the form's
     * top-level _elementIndex regardless of appendName (HTML_QuickForm::addElement() always
     * indexes the element/group it is given), so elementExists() on the group name is the reliable
     * existence check.
     *
     * @param \moodleform $form Form instance.
     * @return \MoodleQuickForm
     */
    private function get_mform($form): \MoodleQuickForm {
        $reflection = new \ReflectionClass($form);
        $property = $reflection->getProperty('_form');
        $property->setAccessible(true);

        return $property->getValue($form);
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
     * definition() only offers activities where completion depends on a grade item
     * (`!is_null($cm->completiongradeitemnumber)` AND `COMPLETION_TRACKING_AUTOMATIC`), and only
     * builds the enable{cond}_{gid} / {cond}_{gid} threshold elements for the resolved $cm inside
     * the `if ($cm)` branch.
     *
     * The actual gate is NOT the activity's own fields: quiz already defaults 'grade' to 100 (a
     * real, resolvable grade_item) when not specified, so an explicit 'grade' is not required.
     * The real gate is course/modlib.php's add_moduleinfo(): it only copies completion,
     * completionusegrade and completiongradeitemnumber from $moduleinfo onto the stored $cm inside
     * `if ($completion->is_enabled()) { ... }` (course/modlib.php ~line 84). completion_info::
     * is_enabled() requires BOTH site-wide $CFG->enablecompletion (already on in this environment)
     * AND $course->enablecompletion (lib/completionlib.php ~line 296-312) to be truthy - when the
     * course itself does not have completion tracking enabled, $newcm->completion and
     * $newcm->completiongradeitemnumber are silently left at their defaults (0 / null) no matter
     * what 'completion'/'completionusegrade' were passed to the generator. This is exactly the gap
     * in the Behat scenario: the feature's Background creates course "C1" without 'enablecompletion',
     * so the quiz's completiongradeitemnumber stayed null and definition() filtered it out, matching
     * core's own convention (see badges/tests/behat/criteria_activity.feature, which sets
     * 'enablecompletion' => 1 on the course and does not pass 'grade' to the quiz either).
     *
     * @covers ::definition
     */
    public function test_definition_builds_threshold_elements_for_eligible_activity(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'idnumber' => 'quiz1',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);

        // Explicit, not incidental: pin WHY the activity is eligible before asserting on the form.
        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($quiz->cmid);
        $this->assertNotNull(
            $cm->completiongradeitemnumber,
            'completiongradeitemnumber must be non-null for the activity to be offered by definition()'
        );
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $cm->completion);

        $gradeitem = \grade_item::fetch(['iteminstance' => $quiz->id, 'itemmodule' => 'quiz', 'itemtype' => 'mod']);
        $this->assertNotFalse($gradeitem, 'A resolvable grade_item is required by add_grade_elements()');

        $ajaxformdata = [
            'courseid' => $course->id,
            'coursemodule' => $cm->id,
        ];

        $form = new dynamic_grade_in_activity_form(null, null, 'post', '', null, true, $ajaxformdata);
        $mform = $this->get_mform($form);

        // The threshold groups are only added inside add_grade_elements(), which definition()
        // only calls when $cm resolved to an eligible activity - their presence pins the fix.
        $this->assertTrue($mform->elementExists('gradegtegroup_' . $gradeitem->id));
        $this->assertTrue($mform->elementExists('gradeltgroup_' . $gradeitem->id));

        // The enable* checkboxes DO surface via exportValues() (advcheckbox always exports 0/1),
        // and pin that both thresholds start unchecked/disabled by default.
        $values = $this->export_values($form);
        $this->assertArrayHasKey('enablegradegte_' . $gradeitem->id, $values);
        $this->assertArrayHasKey('enablegradelt_' . $gradeitem->id, $values);
        $this->assertEquals(0, $values['enablegradegte_' . $gradeitem->id]);
        $this->assertEquals(0, $values['enablegradelt_' . $gradeitem->id]);
    }

    /**
     * Negative control for the previous test: pins that the *course-level* 'enablecompletion' flag
     * - not the activity's own 'completion'/'completionusegrade' fields - is the actual gate. When
     * the course does not have completion tracking enabled, completion_info::is_enabled() returns
     * false and add_moduleinfo() (course/modlib.php ~line 84) never copies 'completion' or
     * 'completiongradeitemnumber' onto the stored $cm, leaving both at their defaults (0 / null)
     * even though the quiz was created with 'completion' => COMPLETION_TRACKING_AUTOMATIC and
     * 'completionusegrade' => 1. This was the actual reason the pre-fix Behat scenario rendered
     * zero threshold rows: the feature's Background course lacked 'enablecompletion'.
     *
     * @covers ::definition
     */
    public function test_definition_excludes_activity_when_course_completion_disabled(): void {
        $this->resetAfterTest(true);

        // Deliberately omit 'enablecompletion' on the course - this is the only difference from
        // the eligible-activity test above.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'idnumber' => 'quiz1',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        // Expected: the generator itself warns when completion is requested on an activity whose
        // course has not enabled completion tracking - exactly the misconfiguration this test pins.
        $this->assertDebuggingCalled();

        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($quiz->cmid);

        $this->assertNull(
            $cm->completiongradeitemnumber,
            'Without course-level enablecompletion, add_moduleinfo() must not persist ' .
            'completiongradeitemnumber even though completionusegrade was requested'
        );
        $this->assertEquals(0, $cm->completion, 'completion tracking itself must also stay disabled');

        $ajaxformdata = [
            'courseid' => $course->id,
            'coursemodule' => $cm->id,
        ];

        $form = new dynamic_grade_in_activity_form(null, null, 'post', '', null, true, $ajaxformdata);
        $gradeitem = \grade_item::fetch(['iteminstance' => $quiz->id, 'itemmodule' => 'quiz', 'itemtype' => 'mod']);

        // definition() must filter the ineligible activity out: no threshold groups are built.
        $mform = $this->get_mform($form);
        $this->assertFalse($mform->elementExists('gradegtegroup_' . $gradeitem->id));
        $this->assertFalse($mform->elementExists('gradeltgroup_' . $gradeitem->id));
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
