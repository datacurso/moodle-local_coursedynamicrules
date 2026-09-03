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

    /**
     * Build a rule row, optionally with components.
     *
     * @param int $courseid
     * @param int $conditions How many condition rows to attach.
     * @param int $actions How many action rows to attach.
     * @return \stdClass The rule record.
     */
    private function rule_with(int $courseid, int $conditions, int $actions): \stdClass {
        global $DB;

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'Completeness probe',
            'description' => '',
            'active' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        for ($i = 0; $i < $conditions; $i++) {
            $DB->insert_record('local_coursedynamicrules_condition', (object) [
                'ruleid' => $ruleid, 'name' => 'c', 'conditiontype' => 'complete_activity', 'params' => '{}',
            ]);
        }
        for ($i = 0; $i < $actions; $i++) {
            $DB->insert_record('local_coursedynamicrules_action', (object) [
                'ruleid' => $ruleid, 'name' => 'a', 'actiontype' => 'sendnotification', 'params' => '{}',
            ]);
        }

        return $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
    }

    /**
     * Run the form's validation for a rule with the given payload.
     *
     * @param \stdClass $rule
     * @param array $data
     * @return array Errors keyed by element.
     */
    private function validation_of(\stdClass $rule, array $data): array {
        $form = new rule_form(
            new \moodle_url('/local/coursedynamicrules/editrule.php', ['courseid' => $rule->courseid]),
            ['rule' => $rule, 'courseid' => $rule->courseid]
        );

        return $form->validation($data, []);
    }

    /**
     * A brand-new rule cannot be created already active.
     *
     * Activation is the moment the rule locks forever, and a new rule has zero conditions and zero
     * actions by definition: activating it would produce a locked rule that can never fire and can
     * never be completed - its only exit is deletion. This was the first critical the adversarial
     * review found in the PLAN: the most common flow (create with the box ticked, add components
     * after) would have become data loss. Product decision: refuse with a validation error, keep
     * the checkbox.
     *
     * @covers ::validation
     */
    public function test_a_new_rule_cannot_be_born_active(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $errors = $this->validation_of(
            (object) ['courseid' => $courseid],
            ['name' => 'New rule', 'active' => 1]
        );

        $this->assertArrayHasKey('active', $errors, 'A rule with no components must not be activatable.');
    }

    /**
     * An incomplete existing rule cannot be activated either - and the completeness is BOTH halves.
     *
     * @covers ::validation
     */
    public function test_an_incomplete_rule_cannot_be_activated(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $onlyconditions = $this->rule_with($courseid, 1, 0);
        $errors = $this->validation_of($onlyconditions, [
            'id' => $onlyconditions->id, 'name' => 'x', 'active' => 1,
        ]);
        $this->assertArrayHasKey('active', $errors, 'Conditions without actions cannot act on anybody.');

        $onlyactions = $this->rule_with($courseid, 0, 1);
        $errors = $this->validation_of($onlyactions, [
            'id' => $onlyactions->id, 'name' => 'x', 'active' => 1,
        ]);
        $this->assertArrayHasKey('active', $errors, 'Actions without conditions never fire.');
    }

    /**
     * Validation never speaks about rules of other courses - or about rules that do not exist.
     *
     * Round-3 confirmed finding, the same ordering class as the delete endpoints: validation ran
     * the lock and completeness checks on the RAW submitted hidden id, before ownership - so a
     * tampered id from another course answered with the incompleteness error (state oracle) and
     * a missing id exploded with a dml exception from inside validation. Ownership speaks first:
     * a non-owned or missing id validates clean here and is refused uniformly at write time by
     * resolve_writable_ruleid(), leaking nothing.
     *
     * @covers ::validation
     */
    public function test_validation_never_speaks_about_foreign_or_missing_rules(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $mycourseid = (int) $this->getDataGenerator()->create_course()->id;
        $foreign = $this->rule_with((int) $this->getDataGenerator()->create_course()->id, 0, 0);

        $errors = $this->validation_of(
            (object) ['courseid' => $mycourseid],
            ['id' => (int) $foreign->id, 'name' => 'Tampered', 'active' => 1]
        );
        $this->assertArrayNotHasKey(
            'active',
            $errors,
            'A foreign rule\'s incompleteness must not leak through a validation error.'
        );

        $errors = $this->validation_of(
            (object) ['courseid' => $mycourseid],
            ['id' => 999999, 'name' => 'Tampered', 'active' => 1]
        );
        $this->assertArrayNotHasKey('active', $errors, 'A missing id neither errors nor throws here.');
    }

    /**
     * A sealed INCOMPLETE rule keeps its active toggle - the upgrade population depends on it.
     *
     * Born green as a regression pin (its red counterfactual is deleting the locked-skip branch):
     * the 2026083002 upgrade deliberately seals every active rule, component-less ones included.
     * If validation demanded completeness from a sealed rule, every such rule that gets paused
     * could never be reactivated - trapped by a check asking for components it can never gain.
     *
     * @covers ::validation
     */
    public function test_a_sealed_incomplete_rule_keeps_its_toggle(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $rule = $this->rule_with($courseid, 0, 0);
        $DB->set_field('local_coursedynamicrules_rule', 'timeactivated', time(), ['id' => $rule->id]);
        $rule = $DB->get_record('local_coursedynamicrules_rule', ['id' => $rule->id], '*', MUST_EXIST);

        $errors = $this->validation_of($rule, ['id' => (int) $rule->id, 'name' => $rule->name, 'active' => 1]);

        $this->assertArrayNotHasKey(
            'active',
            $errors,
            'Reactivating a sealed rule must never demand components a sealed rule can never gain.'
        );
    }

    /**
     * A complete rule activates, and saving anything while NOT activating never trips the check.
     *
     * @covers ::validation
     */
    public function test_a_complete_rule_activates_and_inactive_saves_pass(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $complete = $this->rule_with($courseid, 1, 1);
        $this->assertArrayNotHasKey('active', $this->validation_of($complete, [
            'id' => $complete->id, 'name' => 'x', 'active' => 1,
        ]), 'One condition and one action: activatable.');

        $empty = $this->rule_with($courseid, 0, 0);
        $this->assertArrayNotHasKey('active', $this->validation_of($empty, [
            'id' => $empty->id, 'name' => 'x',
        ]), 'Saving without activating is always allowed - the gate is on activation, not on saving.');
    }
}
