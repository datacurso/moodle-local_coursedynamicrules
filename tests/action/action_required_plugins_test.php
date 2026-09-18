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

namespace local_coursedynamicrules\action\createaiactivity;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/testable_createaiactivity_action.php');

/**
 * Tests the gate that decides whether an action's edit form can do anything at all.
 *
 * MDL-E2E-007: the edit pencil must not be offered when a plugin the form needs is missing. Both
 * action forms return from definition() as soon as a required plugin is absent, so the pencil led to
 * a page with notifications and no fields - a control that promises an edit it cannot deliver. The
 * listings already follow "never offer what would be refused" for capabilities and for the
 * activation lock; this is the same rule for dependencies.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\core\action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class action_required_plugins_test extends \advanced_testcase {
    /**
     * Clear the fixture's static seams between tests.
     */
    protected function tearDown(): void {
        testable_createaiactivity_action::reset();
        parent::tearDown();
    }

    /**
     * Build an action instance whose declared requirements are the given list.
     *
     * @param array $requiredplugins Plugin definitions to report as required.
     * @return testable_createaiactivity_action
     */
    private function action_requiring(array $requiredplugins): testable_createaiactivity_action {
        testable_createaiactivity_action::$requiredplugins = $requiredplugins;

        return new testable_createaiactivity_action(
            (object) ['ruleid' => 1, 'actiontype' => 'createaiactivity', 'params' => json_encode([])],
            1
        );
    }

    /**
     * An action whose dependency is absent reports that its form cannot be edited.
     *
     * @covers ::has_its_required_plugins
     */
    public function test_an_action_with_a_missing_dependency_cannot_be_edited(): void {
        $this->resetAfterTest(true);

        $action = $this->action_requiring([
            [
                'pluginname' => 'local_definitelynotinstalled',
                'downloadurl' => 'https://moodle.org/plugins/local_definitelynotinstalled',
            ],
        ]);

        $this->assertFalse(
            $action->has_its_required_plugins(),
            'A form that returns early on a missing plugin has nothing to edit.'
        );
    }

    /**
     * And one whose dependencies are all present reports that it can, so the gate cannot pass by
     * hiding every pencil.
     *
     * @covers ::has_its_required_plugins
     */
    public function test_an_action_whose_dependencies_are_present_can_be_edited(): void {
        $this->resetAfterTest(true);

        // This plugin is necessarily installed while its own tests are running.
        $action = $this->action_requiring([
            [
                'pluginname' => 'local_coursedynamicrules',
                'downloadurl' => 'https://moodle.org/plugins/local_coursedynamicrules',
            ],
        ]);

        $this->assertTrue($action->has_its_required_plugins());
    }

    /**
     * An action that declares no dependency is always editable: the base class answers for every
     * action that never had this problem.
     *
     * @covers ::required_plugins
     * @covers ::has_its_required_plugins
     */
    public function test_an_action_declaring_no_dependency_is_editable(): void {
        $this->resetAfterTest(true);

        $action = $this->action_requiring([]);

        $this->assertSame([], testable_createaiactivity_action::$requiredplugins);
        $this->assertTrue($action->has_its_required_plugins());
    }

    /**
     * The real AI action declares the dependencies its own form checks - the list must live in one
     * place, or the pencil and the form can disagree about what is needed.
     *
     * @covers ::required_plugins
     */
    public function test_the_ai_action_declares_what_its_form_requires(): void {
        $this->resetAfterTest(true);

        $declared = array_column(createaiactivity_action::required_plugins(), 'pluginname');

        $this->assertContains('availability_user', $declared);
        $this->assertContains('local_coursegen', $declared);
        $this->assertContains('aiprovider_datacurso', $declared);
    }
}
