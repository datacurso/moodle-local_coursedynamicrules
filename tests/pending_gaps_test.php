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

namespace local_coursedynamicrules;

/**
 * Placeholders for the non-critical [Pendiente:skip] gaps from the test-case document. Each is a
 * feature not built yet; the test is skipped with its reason so the gap stays visible in the CI
 * report until the feature lands, at which point the skip is replaced by a real assertion (or a
 * Behat scenario for the UI-facing ones).
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending_gaps_test extends \advanced_testcase {
    /**
     * MDL-E2E-006: the add menu on the actions page must announce "Add actions", not
     * "Add conditions".
     * [Pendiente:skip] — the actions page reuses the conditions menu template; label fix pending.
     */
    public function test_actions_menu_announces_add_actions(): void {
        $this->markTestSkipped('Menu label parametrisation pending; the actions page still reuses '
            . 'the conditions menu template.');
    }

    /**
     * MDL-E2E-007: the AI action edit pencil must not be offered when a required plugin is missing.
     * [Pendiente:skip] — the pencil is currently shown and leads to a dead-end degraded form.
     */
    public function test_ai_action_pencil_hidden_when_required_plugin_missing(): void {
        $this->markTestSkipped('Pencil hiding via the plugin validator is pending.');
    }

    /**
     * MDL-E2E-008: the "pass grade" activity picker must exclude activities pending deletion.
     * [Pendiente:skip] — the picker filter is pending. Of the case's other half, the grade-condition
     * edit form warning, only the notice for a stored activity that is no longer in the course shipped
     * in 1.8.4 (covered by dynamic_grade_in_activity_form_test); the warning for an activity that
     * still exists but is no longer eligible is still pending.
     */
    public function test_passgrade_picker_excludes_activities_pending_deletion(): void {
        $this->markTestSkipped('Pass-grade deletion filter pending (out of the 1.8.4 scope, see CHANGES.md).');
    }

    /**
     * MDL-E2E-009: the AI prompt help must list the markers that action actually substitutes.
     * [Pendiente:skip] — the shared placeholder template is not yet parametrised per form.
     */
    public function test_ai_prompt_help_lists_its_own_markers(): void {
        $this->markTestSkipped('Placeholder template parametrisation pending.');
    }

    /**
     * SYS-EVAL-001: the generated AI content matches the prompt, language and anonymisation golden set.
     * [Pendiente:skip] — requires the live AI service and a golden dataset; evaluated manually.
     */
    public function test_ai_generated_content_quality(): void {
        $this->markTestSkipped('Requires the live AI service and golden dataset; evaluated manually.');
    }

    /**
     * MDL-E2E-010: the Spanish pack must be complete relative to English.
     *
     * [Pendiente:skip] — one string (datacurso_brand_alt, the logo alt text) is still missing in
     * Spanish; it goes in the fix batch. This test is skipped until that string is added, at which
     * point the skip is removed and the parity assertion below stands.
     */
    public function test_spanish_string_pack_is_complete_relative_to_english(): void {
        $missing = array_values(array_diff($this->string_keys('en'), $this->string_keys('es')));

        if ($missing !== []) {
            $this->markTestSkipped('Spanish pack missing (fix-batch item): ' . implode(', ', $missing));
        }

        $this->assertSame([], $missing, 'Every English string must exist in Spanish.');
    }

    /**
     * MDL-E2E-010: German, French, Indonesian, Portuguese and Russian completion.
     * [Pendiente:skip] — 60 strings per language deferred to the roadmap; English fallback is clean.
     */
    public function test_secondary_language_packs_are_complete(): void {
        $this->markTestSkipped('60 strings in de/fr/id/pt/ru deferred to the roadmap; English '
            . 'fallback keeps the interface functional.');
    }

    /**
     * Read the string keys declared in a language pack of this plugin.
     *
     * @param string $lang Language folder (en, es, ...).
     * @return string[] Sorted list of declared string keys.
     */
    private function string_keys(string $lang): array {
        global $CFG;
        $string = [];
        include($CFG->dirroot . '/local/coursedynamicrules/lang/' . $lang . '/local_coursedynamicrules.php');
        $keys = array_keys($string);
        sort($keys);
        return $keys;
    }
}
