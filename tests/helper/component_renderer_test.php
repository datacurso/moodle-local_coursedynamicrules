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

        // The raw payload must not survive into the HTML.
        $this->assertStringNotContainsString('<script>', $html);
        // It must appear escaped instead.
        $this->assertStringContainsString('&lt;script&gt;', $html);
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
}
