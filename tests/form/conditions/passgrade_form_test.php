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
 * Tests for the "pass grade" activity picker and its server-side validation.
 *
 * Moodle keeps a module in modinfo while its deletion runs in the background, flagged with
 * deletioninprogress: the condition's own evaluation already treats such an activity as gone
 * (passgrade_condition::is_condition_met), so a form that offers it - or accepts it on save -
 * stores a condition that can never be met and that a sealed rule can no longer be edited out of.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\form\conditions\passgrade_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class passgrade_form_test extends \advanced_testcase {
    /**
     * Load the testable subclass used to reach the mform by inheritance.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../../fixtures/testable_passgrade_form.php');
    }

    /**
     * Build a course with a graded activity under automatic completion, requiring a passing grade
     * unless told otherwise.
     *
     * @param bool $requirepass Whether completion requires a passing grade (what qualifies it).
     * @return array [\stdClass $course, \stdClass $cm]
     */
    private function create_passgrade_activity(bool $requirepass = true): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'grade' => 10,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => $requirepass ? 1 : 0,
        ]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);

        return [$course, $cm];
    }

    /**
     * Start the module's deletion the way the interface does and assert the state under test:
     * the module must still be in modinfo, flagged as being deleted.
     *
     * @param int $cmid Course module id.
     * @param int $courseid Course id.
     * @return void
     */
    private function start_deleting(int $cmid, int $courseid): void {
        global $DB;

        // The flag only appears when a plugin answers course_module_background_deletion_recommended;
        // in core only tool_recyclebin does, and only while enabled. Turned on here rather than
        // trusted as a site default, or this test silently measures the hard-deleted case instead.
        set_config('coursebinenable', 1, 'tool_recyclebin');
        course_delete_module($cmid, true);

        $this->assertEquals(
            1,
            $DB->get_field('course_modules', 'deletioninprogress', ['id' => $cmid]),
            'Precondition: the deletion must be in progress, not finished.'
        );
        $this->assertArrayHasKey(
            $cmid,
            get_fast_modinfo($courseid)->get_cms(),
            'Precondition: Moodle keeps the module in modinfo while its deletion runs.'
        );
    }

    /**
     * Build the form for a course, the way the condition pages do: creating when no stored
     * condition is given, editing that condition otherwise.
     *
     * @param int $courseid Course id.
     * @param int|null $storedcmid The cmid the condition being edited has stored, if any.
     * @return testable_passgrade_form
     */
    private function form_for(int $courseid, ?int $storedcmid = null): testable_passgrade_form {
        $customdata = [
            'courseid' => $courseid,
            'ruleid' => 0,
            'type' => 'passgrade',
        ];
        if ($storedcmid !== null) {
            $customdata['record'] = (object) ['cmid' => $storedcmid];
        }

        return new testable_passgrade_form(null, $customdata);
    }

    /**
     * The rendered picker markup, where each offered activity appears as an option value.
     *
     * @param testable_passgrade_form $form Form instance.
     * @return string
     */
    private function picker_html(testable_passgrade_form $form): string {
        return $form->get_mform_for_test()->getElement('coursemodule')->toHtml();
    }

    /**
     * MDL-E2E-008: the picker must not offer an activity whose deletion is already running.
     *
     * @covers ::definition
     */
    public function test_the_picker_omits_an_activity_whose_deletion_is_in_progress(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();
        $this->start_deleting($cm->id, $course->id);

        $this->assertStringNotContainsString(
            'value="' . $cm->id . '"',
            $this->picker_html($this->form_for($course->id)),
            'An activity being deleted must not be offered: choosing it stores a condition that can never be met.'
        );
    }

    /**
     * A healthy activity that qualifies must still be offered, so the filter cannot pass by
     * rejecting everything.
     *
     * @covers ::definition
     */
    public function test_the_picker_still_offers_a_healthy_qualifying_activity(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();

        $this->assertStringContainsString(
            'value="' . $cm->id . '"',
            $this->picker_html($this->form_for($course->id)),
            'An activity with automatic completion and a required passing grade must be offered.'
        );
    }

    /**
     * MDL-E2E-008: the server must refuse an activity whose deletion is already running.
     *
     * The picker filter only covers a freshly loaded form. A form opened before the deletion
     * started and submitted after it began still carries the id, and the module is still in
     * modinfo, so the existence check alone lets it through.
     *
     * @covers ::validation
     */
    public function test_saving_an_activity_whose_deletion_is_in_progress_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();
        $this->start_deleting($cm->id, $course->id);

        $errors = $this->form_for($course->id)->validation(['coursemodule' => $cm->id], []);

        $this->assertArrayHasKey(
            'coursemodule',
            $errors,
            'Saving an activity that is being deleted must be refused on the form, not stored.'
        );
        $this->assertSame(
            get_string('componenttargetmissing', 'local_coursedynamicrules'),
            $errors['coursemodule'],
            'The refusal must say why - the activity is no longer available - not merely that a choice is missing.'
        );
    }

    /**
     * The sequence that makes the gap dangerous, as one story: the activity is offered when the
     * form loads, its deletion starts while the form is open, and the same id is then submitted.
     * The picker filter cannot help here; only the server can, and it must read the current state.
     *
     * @covers ::definition
     * @covers ::validation
     */
    public function test_an_activity_offered_at_load_is_refused_once_its_deletion_starts(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();

        $this->assertStringContainsString(
            'value="' . $cm->id . '"',
            $this->picker_html($this->form_for($course->id)),
            'Precondition: the activity is legitimately offered while it is healthy.'
        );

        $this->start_deleting($cm->id, $course->id);

        $errors = $this->form_for($course->id)->validation(['coursemodule' => $cm->id], []);

        $this->assertArrayHasKey(
            'coursemodule',
            $errors,
            'An id that was valid at load time must be refused at submit time once its deletion has started.'
        );
    }

    /**
     * Pins what the picker's eligibility test already does, because the fix edits that same line:
     * an activity that does not require a passing grade was never offered and must stay that way.
     *
     * @covers ::definition
     */
    public function test_the_picker_omits_an_activity_without_a_passing_grade_requirement(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity(false);

        $this->assertStringNotContainsString(
            'value="' . $cm->id . '"',
            $this->picker_html($this->form_for($course->id)),
            'Only activities whose completion requires a passing grade qualify for this condition.'
        );
    }

    /**
     * MDL-E2E-008: editing a condition whose stored activity is being deleted must say so, the way
     * the grade condition's form already does, instead of opening a blank picker with no explanation.
     *
     * @covers ::definition
     */
    public function test_editing_a_condition_whose_activity_is_being_deleted_shows_the_notice(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();
        $this->start_deleting($cm->id, $course->id);

        $mform = $this->form_for($course->id, $cm->id)->get_mform_for_test();

        $this->assertTrue(
            $mform->elementExists('targetmissing'),
            'A stored activity that is being deleted must be announced on the edit form.'
        );
    }

    /**
     * The notice's other trigger: a stored activity that was already deleted outright. The picker
     * then has nothing to select, and the reason must be on the form rather than a blank widget.
     *
     * @covers ::definition
     */
    public function test_editing_a_condition_whose_activity_was_deleted_shows_the_notice(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('coursebinenable', 0, 'tool_recyclebin');

        [$course, $cm] = $this->create_passgrade_activity();
        course_delete_module($cm->id);
        $this->assertArrayNotHasKey(
            $cm->id,
            get_fast_modinfo($course->id)->get_cms(),
            'Precondition: the module is gone from modinfo once its deletion completed.'
        );

        $mform = $this->form_for($course->id, $cm->id)->get_mform_for_test();

        $this->assertTrue(
            $mform->elementExists('targetmissing'),
            'A stored activity that no longer exists must be announced on the edit form.'
        );
    }

    /**
     * A condition on a healthy activity carries no notice, so the notice cannot pass by always
     * being shown.
     *
     * @covers ::definition
     */
    public function test_editing_a_condition_on_a_healthy_activity_shows_no_notice(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();

        $mform = $this->form_for($course->id, $cm->id)->get_mform_for_test();

        $this->assertFalse(
            $mform->elementExists('targetmissing'),
            'A healthy stored activity must not be announced as missing.'
        );
    }

    /**
     * A healthy qualifying activity must still be accepted, so the refusal cannot pass by
     * rejecting every activity.
     *
     * @covers ::validation
     */
    public function test_saving_a_healthy_qualifying_activity_is_accepted(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $cm] = $this->create_passgrade_activity();

        $errors = $this->form_for($course->id)->validation(['coursemodule' => $cm->id], []);

        $this->assertArrayNotHasKey(
            'coursemodule',
            $errors,
            'A healthy activity with a required passing grade must be accepted.'
        );
    }
}
