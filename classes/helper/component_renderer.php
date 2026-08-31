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

use html_writer;

/**
 * Builds the HTML shown for condition and action descriptions on the rules list.
 *
 * Descriptions can embed configurable content (for example the AI activity prompt), so the
 * rules list must escape them at this output boundary. The Mustache views for the condition
 * and action pages already escape via {{description}}; this helper is the equivalent escape
 * boundary for the manually assembled table in rules.php.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class component_renderer {
    /**
     * Build the paragraph list of component descriptions.
     *
     * @param array $instances Condition or action instances, each exposing get_header() and get_description().
     * @return string HTML with one paragraph per component that has both a header and a description.
     */
    public static function descriptions_html(array $instances): string {
        $html = '';
        foreach ($instances as $instance) {
            $header = $instance->get_header();
            $description = $instance->get_description();
            if (!empty($header) && !empty($description)) {
                // The LISTING trims for uniform row height (product directive 2026-08-31); the
                // magnifier's page shows the untrimmed description. Presentation-layer cut on
                // purpose: shorten the raw text FIRST, escape after - the other order can slice
                // an HTML entity in half and leak a broken escape into the cell.
                $html .= html_writer::tag('p', s(shorten_text($description, 80)));
            }
        }
        return $html;
    }

    /**
     * Return a component's description escaped for safe HTML output.
     *
     * Descriptions can embed configurable content (for example the AI activity prompt), so any page
     * that echoes a single description into HTML (delete confirmation pages, notifications) must
     * escape it at that boundary.
     *
     * @param object $instance Condition or action instance exposing get_description().
     * @return string The escaped description.
     */
    public static function escaped_description($instance): string {
        return s($instance->get_description());
    }
}
