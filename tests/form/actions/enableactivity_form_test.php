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

namespace local_coursedynamicrules\form\actions;

/**
 * Tests for the enableactivity_form preload hook.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\form\actions\enableactivity_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enableactivity_form_test extends \advanced_testcase {
    /**
     * Load the testable_enableactivity_form fixture used to reach the real mform by inheritance.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../../fixtures/testable_enableactivity_form.php');
    }

    /**
     * MDL-UNIT-020: enable-activity preload maps stored course modules down to a plain id list.
     *
     * preload_defaults() maps the stored coursemodules objects down to a plain id list.
     *
     * The form's preload_defaults() delegates verbatim to the pure form_preload::enableactivity()
     * mapper, so the mapping is exercised through that public, instantiation-free entry point
     * instead of reaching the form's protected method by reflection.
     *
     * @covers ::preload_defaults
     */
    public function test_preload_defaults_maps_coursemodules_to_id_list(): void {
        $result = \local_coursedynamicrules\local\form_preload::enableactivity((object) [
            'coursemodules' => [
                (object) ['id' => 12, 'visible' => 1, 'visibleoncoursepage' => 1],
                (object) ['id' => 34, 'visible' => 0, 'visibleoncoursepage' => 0],
            ],
        ]);

        $this->assertSame(['coursemodules' => [12, 34]], $result);
    }

    /**
     * MDL-UNIT-020: enable-activity preload returns an empty course-module list when none are stored.
     *
     * preload_defaults() returns an empty coursemodules list when none are stored.
     *
     * Exercised through the pure form_preload::enableactivity() mapper the form delegates to.
     *
     * @covers ::preload_defaults
     */
    public function test_preload_defaults_handles_empty_coursemodules(): void {
        $result = \local_coursedynamicrules\local\form_preload::enableactivity((object) ['coursemodules' => []]);

        $this->assertSame(['coursemodules' => []], $result);
    }

    /**
     * MDL-UNIT-020: enable-activity definition sets PARAM_INT on the real coursemodules element.
     *
     * definition() used to call setType('coursemodule', ...) - singular, naming a non-existent
     * element - so the real 'coursemodules' multi-select never had its PARAM_INT filter registered
     * (FIX2-11).
     *
     * FIX3-1: this genuinely needs the availability_user plugin - definition() early-returns before
     * adding the 'coursemodules' element at all when it is missing, so the setType() assertion below
     * would fail for the wrong reason (missing plugin, not the FIX2-11 regression) in an environment
     * that does not have it installed (e.g. GitHub CI).
     *
     * @covers ::definition
     */
    public function test_definition_sets_int_type_on_the_real_coursemodules_element(): void {
        $this->resetAfterTest(true);

        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; enableactivity_form requires it.');
        }

        $course = $this->getDataGenerator()->create_course();

        $form = new testable_enableactivity_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => $course->id, 'ruleid' => 1]
        );

        $mform = $form->get_mform_for_test();

        $this->assertSame(PARAM_INT, $mform->getCleanType('coursemodules', '5', PARAM_RAW));
    }

    /**
     * MDL-UNIT-020: enable-activity rejects a submission with no activity selected.
     *
     * FIX3-8: submitting with no course module selected must be rejected - silently accepting it
     * would revert EVERY currently-managed module on the next save_action() reconciliation.
     *
     * @covers ::validation
     */
    public function test_validation_rejects_empty_coursemodules_selection(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $form = new enableactivity_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => $course->id, 'ruleid' => 1]
        );

        $errors = $form->validation(['coursemodules' => []], []);

        $this->assertArrayHasKey('coursemodules', $errors);
    }

    /**
     * MDL-UNIT-020: enable-activity accepts a non-empty activity selection.
     *
     * FIX3-8: a non-empty selection must not be rejected.
     *
     * @covers ::validation
     */
    public function test_validation_accepts_non_empty_coursemodules_selection(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $form = new enableactivity_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => $course->id, 'ruleid' => 1]
        );

        $errors = $form->validation(['coursemodules' => [12]], []);

        $this->assertArrayNotHasKey('coursemodules', $errors);
    }

    /**
     * MDL-UNIT-020: enable-activity rejects the forged empty submission even when the degraded form omits the field.
     *
     * When the required availability_user plugin is missing, definition() early-returns before
     * adding the 'coursemodules' element at all - a browser never submits that degraded form, so
     * the only way this validation runs is a forged POST, and it must be REJECTED: the error is
     * never rendered (its element does not exist), but any validation error makes get_data()
     * return null, which is the refusal that keeps save_action() from receiving an action with no
     * target modules. This inverts the earlier FIX4 contract, which skipped the check and let the
     * forged submission through.
     *
     * Mirrors the FIX3-1 skip guard on test_definition_sets_int_type_on_the_real_coursemodules_element():
     * this scenario can only be exercised for real in an environment that does NOT have
     * availability_user installed (e.g. GitHub CI); locally, where the plugin is present, it is
     * skipped - the "element present" branch is already covered by the tests above.
     *
     * @covers ::validation
     */
    public function test_validation_rejects_empty_selection_even_when_element_is_missing(): void {
        $this->resetAfterTest(true);

        if (\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is installed; enableactivity_form always adds coursemodules.');
        }

        $course = $this->getDataGenerator()->create_course();

        $form = new enableactivity_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => $course->id, 'ruleid' => 1]
        );

        $errors = $form->validation(['coursemodules' => []], []);

        $this->assertArrayHasKey('coursemodules', $errors);
    }
}
