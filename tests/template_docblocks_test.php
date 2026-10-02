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
 * Tests that no template of this plugin renders its own documentation.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_docblocks_test extends \advanced_testcase {
    /**
     * A Mustache comment ends at the FIRST "}}" - so a docblock may never contain a Mustache tag.
     *
     * Found in production 2026-09-10: notification_placeholders.mustache documented that "Mustache
     * cannot resolve {{#str}} with a key held in a variable", and that example tag's own closing
     * braces ended the comment. Everything after it - two paragraphs of template documentation and
     * a JSON example - rendered as visible text above the marker list, in the AI action form and
     * the notification action form alike. phpcs does not read Mustache and no test rendered the
     * template, so a green suite and a clean linter said nothing about it.
     *
     * This walks every template instead of just that one, because the trap belongs to the syntax,
     * not to that file: the next docblock that shows a helper by example breaks the same way.
     *
     * @coversNothing
     */
    public function test_no_template_docblock_closes_in_the_middle_of_a_line(): void {
        $templates = glob(__DIR__ . '/../templates/*.mustache');
        $this->assertNotEmpty($templates, 'Sanity: the search must find the templates it audits.');

        foreach ($templates as $path) {
            $source = file_get_contents($path);
            $offset = 0;

            while (($open = strpos($source, '{{!', $offset)) !== false) {
                $close = strpos($source, '}}', $open + 3);
                $this->assertNotFalse($close, basename($path) . ': a {{! comment is never closed.');

                // The convention every Moodle template follows: the closing braces sit alone on
                // their line. Anything else means the comment ended earlier than its author meant.
                $linestart = strrpos(substr($source, 0, $close), "\n");
                $prefix = substr($source, $linestart === false ? 0 : $linestart + 1, $close - ($linestart + 1));

                $this->assertSame(
                    '',
                    trim($prefix),
                    basename($path) . ': a docblock comment closes in the middle of a line, after "'
                        . trim($prefix) . '". A Mustache comment ends at the first "}}", so every '
                        . 'character after that point is rendered to the page. Write the example '
                        . 'without its closing braces.'
                );

                $offset = $close + 2;
            }
        }
    }

    /**
     * And the marker template renders its markers, and not one word of its own documentation.
     *
     * The executable half of the audit above: the static check pins the syntax, this pins what a
     * teacher actually sees on the two action forms that include this template.
     *
     * @coversNothing
     */
    public function test_the_marker_template_renders_markers_and_none_of_its_documentation(): void {
        global $PAGE;

        $this->resetAfterTest(true);

        $html = $PAGE->get_renderer('core')->render_from_template(
            'local_coursedynamicrules/notification_placeholders',
            [
                'markers' => [
                    ['name' => 'coursename', 'label' => 'Course name'],
                    ['name' => 'courseurl', 'label' => 'Course URL'],
                ],
            ]
        );

        // What the template is for.
        $this->assertStringContainsString('{$a->coursename}', $html);
        $this->assertStringContainsString('Course name', $html);
        $this->assertStringContainsString('{$a->courseurl}', $html);

        // And nothing of what it says about itself.
        foreach (
            [
                'Context variables required',
                'the caller looks it up',
                'Example context',
                'Data attributes required',
                '@template',
            ] as $ownwords
        ) {
            $this->assertStringNotContainsString(
                $ownwords,
                $html,
                'The template rendered its own documentation to the page: "' . $ownwords . '".'
            );
        }
    }
}
