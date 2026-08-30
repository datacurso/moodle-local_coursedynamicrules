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

namespace local_coursedynamicrules\form;

/**
 * The form that creates and edits a rule.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\form\rule_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_form_test extends \advanced_testcase {
    /**
     * Building the form for a rule that does not exist yet emits no PHP diagnostics.
     *
     * editrule.php hands this form `new stdClass()` when somebody is creating a rule rather than
     * editing one - there is no record yet, so there is nothing to prefill from. definition() then
     * reads name, description and id off that empty object.
     *
     * On a production site the result is invisible: warnings are not displayed, and setDefault()
     * with null leaves the field empty, which is what a creation form should show anyway. That
     * invisibility is exactly why this survived every review. Behat is stricter - it turns any PHP
     * diagnostic into an exception - so the create-a-rule screen died outright there, taking 18
     * scenarios with it and leaving the acceptance suite unable to reach anything behind that form.
     *
     * The check uses an explicit error handler rather than assertDebuggingCalled(), because this is
     * a PHP-level warning and not a Moodle debugging() call: nothing in the Moodle test API observes
     * it, which is the other half of why nobody noticed.
     *
     * @covers ::definition
     */
    public function test_building_the_form_for_a_new_rule_emits_no_diagnostics(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $diagnostics = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$diagnostics): bool {
            $diagnostics[] = $errstr;
            return true;
        }, E_ALL);

        try {
            new rule_form(
                new \moodle_url('/local/coursedynamicrules/editrule.php', ['courseid' => $courseid]),
                ['rule' => new \stdClass(), 'courseid' => $courseid]
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            [],
            $diagnostics,
            'Creating a rule is the most common thing anybody does with this plugin, and the form '
            . 'that does it must not read properties off an object that has none.'
        );
    }

    /**
     * Editing an existing rule still prefills every field from it.
     *
     * The guard added for the creation case must not become a guard that ignores real data: a
     * defensive null coalesce in the wrong place would silence the warning and leave the edit form
     * blank, which is a worse bug than the one it fixed and would look identical in Behat.
     *
     * @covers ::definition
     */
    public function test_editing_an_existing_rule_prefills_its_values(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Rule under edit',
            'description' => 'What this rule is for',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $rule = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);

        $form = new rule_form(
            new \moodle_url('/local/coursedynamicrules/editrule.php', ['courseid' => $courseid]),
            ['rule' => $rule, 'courseid' => $courseid]
        );

        $html = $form->render();

        $this->assertStringContainsString('Rule under edit', $html, 'The name must come back to be edited.');
        $this->assertStringContainsString('What this rule is for', $html, 'And so must the description.');
        $this->assertMatchesRegularExpression(
            '/name="id"[^>]*value="' . $ruleid . '"/',
            $html,
            'The hidden id is what makes this an edit instead of a second rule.'
        );
    }
}
