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
     * @covers ::preload_defaults
     */
    public function test_preload_defaults_maps_coursemodules_to_id_list(): void {
        $reflection = new \ReflectionClass(enableactivity_form::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);

        $result = $method->invoke($form, (object) [
            'coursemodules' => [
                (object) ['id' => 12, 'visible' => 1, 'visibleoncoursepage' => 1],
                (object) ['id' => 34, 'visible' => 0, 'visibleoncoursepage' => 0],
            ],
        ]);

        $this->assertSame(['coursemodules' => [12, 34]], $result);
    }

    /**
     * @covers ::preload_defaults
     */
    public function test_preload_defaults_handles_empty_coursemodules(): void {
        $reflection = new \ReflectionClass(enableactivity_form::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);

        $result = $method->invoke($form, (object) ['coursemodules' => []]);

        $this->assertSame(['coursemodules' => []], $result);
    }

    /**
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

        $form = new enableactivity_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['courseid' => $course->id, 'ruleid' => 1]
        );

        $reflection = new \ReflectionClass($form);
        $property = $reflection->getProperty('_form');
        $property->setAccessible(true);
        $mform = $property->getValue($form);

        $this->assertSame(PARAM_INT, $mform->getCleanType('coursemodules', '5', PARAM_RAW));
    }

    /**
     * FIX3-8: submitting with no course module selected must be rejected - silently accepting it
     * would revert EVERY currently-managed module on the next save_action() reconciliation.
     *
     * @covers ::validation
     */
    public function test_validation_rejects_empty_coursemodules_selection(): void {
        $this->resetAfterTest(true);

        $reflection = new \ReflectionClass(enableactivity_form::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validation');
        $method->setAccessible(true);

        $errors = $method->invoke($form, ['coursemodules' => []], []);

        $this->assertArrayHasKey('coursemodules', $errors);
    }

    /**
     * FIX3-8: a non-empty selection must not be rejected.
     *
     * @covers ::validation
     */
    public function test_validation_accepts_non_empty_coursemodules_selection(): void {
        $this->resetAfterTest(true);

        $reflection = new \ReflectionClass(enableactivity_form::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validation');
        $method->setAccessible(true);

        $errors = $method->invoke($form, ['coursemodules' => [12]], []);

        $this->assertArrayNotHasKey('coursemodules', $errors);
    }

    /**
     * FIX4: when the required availability_user plugin is missing, definition() early-returns
     * before adding the 'coursemodules' element at all - validation() must not set an error keyed
     * to a non-existent element (it would be silently lost, never rendered) nor throw when calling
     * elementExists() on a real (populated) $_form.
     *
     * Mirrors the FIX3-1 skip guard on test_definition_sets_int_type_on_the_real_coursemodules_element():
     * this scenario can only be exercised for real in an environment that does NOT have
     * availability_user installed (e.g. GitHub CI); locally, where the plugin is present, it is
     * skipped - the "element present" branch is already covered by the tests above.
     *
     * @covers ::validation
     */
    public function test_validation_does_not_set_coursemodules_error_when_element_is_missing(): void {
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

        $this->assertArrayNotHasKey('coursemodules', $errors);
    }
}
