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

namespace local_coursedynamicrules\helper;

use local_coursedynamicrules\action\createaiactivity\createaiactivity_action;
use local_coursedynamicrules\action\sendnotification\sendnotification_action;

/**
 * Tests for the component description renderer used by the rules list.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\helper\component_renderer
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class component_renderer_test extends \advanced_testcase {
    /**
     * A configurable description must be escaped at the rules-list output boundary.
     */
    public function test_escapes_html_in_action_description(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $payload = '<script>alert(document.cookie)</script>';
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode([
                'message' => $payload,
                'generateimages' => false,
                'sectionnum' => 0,
                'beforemod' => null,
            ]),
        ];
        $action = new createaiactivity_action($record, $course->id);

        $html = component_renderer::descriptions_html([$action]);

        // The raw payload must not survive into the HTML. The listing trim can cut
        // through the middle of the payload, so the assertions anchor on the tag OPENING, which
        // survives any cut point: an unescaped '<script' anywhere is the vulnerability.
        $this->assertStringNotContainsString('<script', $html);
        // It must appear escaped instead.
        $this->assertStringContainsString('&lt;script', $html);
    }

    /**
     * Free text longer than the budget is cut with an ellipsis; shorter passes through whole.
     *
     * Reads the constant, so it pins the MECHANISM of the cut and nothing about the value. That is
     * deliberate and it is also not enough on its own - see
     * test_the_listing_still_shows_a_notification_body(), which pins the value. Two adversarial
     * rounds proved why both are needed: with only a constant-relative test, reverting the budget
     * to a number that hid the message body left the whole suite green.
     */
    public function test_free_text_over_the_budget_is_cut(): void {
        $budget = component_renderer::LISTING_FREETEXT_LENGTH;

        $this->assertSame(str_repeat('a', $budget) . '…',
            component_renderer::cut_freetext(str_repeat('a', $budget + 20)));
        $this->assertSame(str_repeat('b', $budget),
            component_renderer::cut_freetext(str_repeat('b', $budget)));
    }

    /**
     * The rules list must still show part of a notification's MESSAGE, whatever the budget is.
     *
     * This is the assertion both judges asked for in round 2, and the one whose absence let two
     * wrong values ship. A notification's description opens with a preamble - "Enviar notificacion
     * '<asunto>' a los usuarios Destinatarios: <roles>. Con copia a: <roles>. Mensaje: " -
     * measured at 120 characters with one recipient role and 196 with five, in Spanish with
     * default role names. Neither the subject (CHAR 255) nor the role list is bounded, so a cut
     * over the COMPOSED sentence can always be swallowed by the preamble: at 80 the list showed no
     * body at all, and at 220 it showed 24 characters with five roles.
     *
     * The fixture is deliberately the hostile end of realistic - five roles and a long subject -
     * because the friendly case passed under both broken values. It runs in ENGLISH, which is the
     * KINDER language here: the English preamble is shorter than the Spanish one, so a fixture
     * that discriminates in English discriminates in Spanish too. (force_current_language('es')
     * was tried and silently did nothing - the Spanish pack is not installed in the PHPUnit
     * database - and a line that does nothing while the docblock cites Spanish numbers is worse
     * than no line, so it is gone.)
     *
     * Falsifier: make get_listing_description() return get_description(), or move the cut back
     * onto the composed string, and this goes red.
     */
    public function test_the_listing_still_shows_a_notification_body(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $body = 'Hola, notamos que no ingresaste al curso en dos semanas. Te dejamos una '
            . 'actividad de refuerzo para retomar el ritmo y no atrasarte con la entrega final.';
        $rulegen = $this->getDataGenerator()->get_plugin_generator('local_coursedynamicrules');
        $ruleid = $rulegen->create_rule($course->id, []);

        $roleids = array_values(array_map(static function ($role) {
            return (int) $role->id;
        }, get_all_roles()));
        $action = new sendnotification_action((object) [
            'id' => 0,
            'ruleid' => $ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Recordatorio de entrega de la actividad final del curso',
                'messagebody' => $body,
                'primaryroleids' => array_slice($roleids, 0, 3),
                'copyroleids' => array_slice($roleids, 3, 2),
            ]),
        ], $course->id);

        $html = component_renderer::descriptions_html([$action]);

        // The first words of the body, escaped exactly as the listing renders them.
        $this->assertStringContainsString(s('Hola, notamos que no ingresaste al curso'), $html,
            'the rules list has to show part of the message, not just the preamble');
        // And the body is still cut, or the row grows without bound.
        $this->assertStringNotContainsString(s('entrega final'), $html,
            'the whole body belongs on the component page, not on the list');
    }

    /**
     * Plain text is preserved (only escaped) and wrapped in a paragraph.
     */
    public function test_plain_description_is_wrapped_and_escaped(): void {
        $component = $this->component_stub('Header', '<b>Bold</b> & clear');

        $html = component_renderer::descriptions_html([$component]);

        $this->assertSame('<p>&lt;b&gt;Bold&lt;/b&gt; &amp; clear</p>', $html);
    }

    /**
     * Components without a header or without a description produce no output.
     */
    public function test_skips_components_without_header_or_description(): void {
        $noheader = $this->component_stub('', 'Has description');
        $nodescription = $this->component_stub('Has header', '');

        $html = component_renderer::descriptions_html([$noheader, $nodescription]);

        $this->assertSame('', $html);
    }

    /**
     * Escaping is a display-only boundary: the underlying prompt stays intact for the AI request.
     */
    public function test_escaping_is_display_only_prompt_stays_intact(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $payload = '<script>alert(1)</script>';
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode([
                'message' => $payload,
                'generateimages' => false,
                'sectionnum' => 0,
                'beforemod' => null,
            ]),
        ];
        $action = new createaiactivity_action($record, $course->id);

        // The description source keeps the raw prompt (so the AI receives it verbatim)...
        $this->assertStringContainsString($payload, $action->get_description());
        // ...while the rendered output boundary escapes it.
        $this->assertStringNotContainsString($payload, component_renderer::descriptions_html([$action]));
    }

    /**
     * A single component description is escaped for use on the delete confirmation pages.
     */
    public function test_escaped_description_escapes_html(): void {
        $component = $this->component_stub('Header', '<script>alert(1)</script>');

        $result = component_renderer::escaped_description($component);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Build a lightweight component exposing the header/description contract.
     *
     * @param string $header Header value.
     * @param string $description Description value.
     * @return object
     */
    private function component_stub(string $header, string $description): object {
        return new class ($header, $description) {
            /** @var string */
            private $header;
            /** @var string */
            private $description;

            /**
             * Store the header and description for the stub component.
             *
             * @param string $header Header value.
             * @param string $description Description value.
             */
            public function __construct(string $header, string $description) {
                $this->header = $header;
                $this->description = $description;
            }

            /**
             * Return the stub header.
             *
             * @return string
             */
            public function get_header(): string {
                return $this->header;
            }

            /**
             * Return the stub description.
             *
             * @return string
             */
            public function get_description(): string {
                return $this->description;
            }

            /**
             * Return the stub's listing description.
             *
             * Same text as get_description(), the way every component that has no unbounded free
             * text behaves - the base classes' default. The stub exists to test the renderer's
             * own escaping and skipping, not a component's cutting policy.
             *
             * @return string
             */
            public function get_listing_description(): string {
                return $this->description;
            }
        };
    }

    /**
     * A name carrying a script payload comes out as visible text, never as markup.
     *
     * The threat is not the form - rule_form.php types the field PARAM_TEXT, which strips tags.
     * It is course restore, which writes the name with no cleaning at all
     * (restore_local_coursedynamicrules_plugin.class.php:95). A rule arriving from a crafted .mbz
     * carries whatever its author typed, and two pages emit it into HTML: the rules list and the
     * delete confirmation, where core_renderer::confirm() passes its message through
     * html_writer::tag('p', ...) untouched.
     *
     * Anchored on the tag OPENING because that is what makes a payload live: an unescaped
     * '&lt;script' anywhere in the output is the vulnerability, whatever follows it.
     *
     * @covers \local_coursedynamicrules\helper\component_renderer::escaped_name
     */
    public function test_a_script_payload_in_a_name_is_escaped(): void {
        $this->resetAfterTest(true);
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);

        $escaped = component_renderer::escaped_name(
            '<script>alert(document.cookie)</script>Refuerzo', $context);

        $this->assertStringNotContainsString('<script', $escaped);
        $this->assertStringNotContainsString('<img', component_renderer::escaped_name(
            '<img src=x onerror=alert(1)>Refuerzo', $context));
    }

    /**
     * An ampersand and a stray angle bracket survive as readable text.
     *
     * PARAM_TEXT lets both through on purpose - core says so at lib/classes/param.php:202,
     * "'&lt;', or '&gt;' are allowed here" - so a legitimate name like "Tareas de A &amp; B" reaches
     * this method raw and has to come out rendering as itself.
     *
     * @covers \local_coursedynamicrules\helper\component_renderer::escaped_name
     */
    public function test_an_ampersand_in_a_name_survives_as_text(): void {
        $this->resetAfterTest(true);
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);

        $escaped = component_renderer::escaped_name('Tareas de A & B', $context);

        $this->assertStringContainsString('&amp;', $escaped);
        $this->assertStringNotContainsString('A & B', $escaped);
    }

    /**
     * A plain name passes through unchanged, and an empty one does not become "null".
     *
     * The second half is the reason the parameter is nullable: the column allows null, and a
     * string cast on null is the kind of silent "null" label that reaches a screen.
     *
     * @covers \local_coursedynamicrules\helper\component_renderer::escaped_name
     */
    public function test_a_plain_name_is_untouched_and_null_is_empty(): void {
        $this->resetAfterTest(true);
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);

        $this->assertSame('Refuerzo de fracciones',
            component_renderer::escaped_name('Refuerzo de fracciones', $context));
        $this->assertSame('', component_renderer::escaped_name(null, $context));
    }
}
