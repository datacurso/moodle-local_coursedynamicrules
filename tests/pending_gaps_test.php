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
 * The non-critical [Pendiente:skip] gaps from the test-case document, and the guarantees promoted
 * out of them. A gap not built yet is skipped with its reason, so it stays visible in the CI report
 * until the feature lands; when it lands, the skip is replaced by a real assertion here (as the
 * Spanish pack's completeness was) or by a Behat scenario for the UI-facing ones.
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
     * The four destination-bearing pages send an operator who may not enter a listing to the course
     * page instead of into a permission error (page_gate::listing_url/component_listing_url, 1.8.4).
     * The decision is covered by effect tests with real roles, and the wiring by an occurrence scan;
     * what nothing covers is FOLLOWING the destination through the browser.
     *
     * [Pendiente:skip] — one Behat scenario per page (delete a rule, delete a component, duplicate,
     * save an edit) with a role holding only that page's own write capability, asserting it lands on
     * the course page. A page script cannot be loaded from PHPUnit, so this is the only way in.
     */
    public function test_the_destination_pages_land_on_the_course_page_without_the_pair(): void {
        $this->markTestSkipped('Behat coverage for the post-work destinations is pending; the '
            . 'decision itself is covered by page_gate_test.');
    }

    /**
     * The "back to the list of rules" link on the component pages keeps that label even when the
     * destination is the course page, which is what a role without the rule pair now gets instead of
     * a permission error (1.8.4).
     *
     * [Pendiente:skip] — the label must come from the same decision that picks the URL. Recorded
     * rather than fixed because the current state is strictly better than the error it replaced.
     */
    public function test_the_back_link_label_matches_its_destination(): void {
        $this->markTestSkipped('Label/destination agreement is pending; the destination is correct, '
            . 'only the wording assumes the listing.');
    }

    /**
     * A gate written before the ownership marker existed (pre-1.8.2) carries no owner, so the
     * refusal that stops two actions opening one activity cannot see it: on a site upgraded from
     * those versions the pair can still be created, and the first action's students lose the
     * activity.
     *
     * [Pendiente:skip] — closing it means guessing whether an unmarked user node belongs to an old
     * action or to a teacher, and refusing a teacher's own restriction would be worse. Documented in
     * CHANGES.md under "Two rules opening the same activity".
     */
    public function test_pre_marker_gates_are_seen_by_the_shared_activity_refusal(): void {
        $this->markTestSkipped('Pre-marker gates are invisible to the refusal by design; see '
            . 'CHANGES.md.');
    }

    /**
     * The refusal lives on the form, so a caller that saves an enable-activity action without it - a
     * course restore, a script, a future web service - can still put two actions on one activity.
     *
     * [Pendiente:skip] — moving the check into save_action() would refuse writes that the restore
     * legitimately performs while it rebuilds a course. Documented in CHANGES.md.
     */
    public function test_the_shared_activity_refusal_also_guards_non_form_writes(): void {
        $this->markTestSkipped('Only the form refuses a shared activity; non-form writers are out '
            . 'of the 1.8.4 scope.');
    }

    /**
     * MDL-E2E-010: the Spanish pack must be complete relative to English.
     *
     * The last gap (datacurso_brand_alt, the logo alt text) closed in 1.8.4, so the skip this test
     * used to take is gone: a skip could never go red, which made the guarantee unfalsifiable - the
     * next English-only string would have been reported as a skip in CI instead of a failure.
     */
    public function test_spanish_string_pack_is_complete_relative_to_english(): void {
        $missing = array_values(array_diff($this->string_keys('en'), $this->string_keys('es')));

        $this->assertSame([], $missing, 'Every English string must exist in Spanish.');
    }

    /**
     * MDL-E2E-010: German, French, Indonesian, Portuguese and Russian completion.
     * [Pendiente:skip] — the missing strings per language are deferred to the roadmap; the English
     * fallback keeps every one of them readable. The skip message counts them rather than naming a
     * number that goes stale on the next string added.
     */
    public function test_secondary_language_packs_are_complete(): void {
        $missing = [];
        foreach (['de', 'fr', 'id', 'pt', 'ru'] as $lang) {
            $missing[$lang] = count(array_diff($this->string_keys('en'), $this->string_keys($lang)));
        }
        $counts = [];
        foreach ($missing as $lang => $count) {
            $counts[] = "$lang: $count";
        }
        $this->markTestSkipped('Strings deferred to the roadmap (' . implode(', ', $counts)
            . '); the English fallback keeps the interface functional.');
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
