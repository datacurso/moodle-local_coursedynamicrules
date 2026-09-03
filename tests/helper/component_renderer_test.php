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
     * A description longer than the listing budget is cut with an ellipsis; a shorter one passes
     * through whole. The cut happens on the plain text BEFORE escaping, so no entity is ever
     * sliced in half.
     *
     * The lengths come from the constant rather than a literal on purpose. When the budget was a
     * hard-coded 80 in both places, this test pinned the number instead of the behaviour - it
     * would have gone red for the fix that RAISED the budget, and it stayed green through the
     * whole time the listing was cutting inside a notification's fixed preamble and showing no
     * message body at all.
     */
    public function test_long_description_is_trimmed_short_one_is_not(): void {
        $budget = component_renderer::LISTING_DESCRIPTION_LENGTH;
        $long = str_repeat('a', $budget + 20);
        $short = str_repeat('b', $budget);

        $html = component_renderer::descriptions_html([
            $this->component_stub('Header', $long),
            $this->component_stub('Header', $short),
        ]);

        $this->assertStringContainsString('<p>' . str_repeat('a', $budget) . '…</p>', $html);
        $this->assertStringNotContainsString(str_repeat('a', $budget + 1), $html);
        $this->assertStringContainsString('<p>' . $short . '</p>', $html);
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
