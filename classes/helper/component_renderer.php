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
     * @var int Characters of a component description the rules list shows before cutting.
     *
     * 80 was the first value and it was measured wrong: it is the budget for the WHOLE composed
     * description, and a notification's description opens with a fixed preamble - "Send
     * notification '<subject>' to users Recipients: <roles>. Copy to: <roles>. Message: " - that
     * runs to 119 characters with one recipient role and 146 with two. The cut landed inside the
     * preamble, so the listing showed no message body at all and two rules with the same subject
     * were indistinguishable. Against 1.8.2, which trims the BODY to 80 and shows the preamble
     * whole, that was a loss of information rather than a tidier row.
     *
     * 220 clears the longest measured preamble and still leaves roughly what 1.8.2 showed of the
     * body. It is a row-height decision, so it lives here as one number to change rather than
     * scattered through the components.
     */
    public const LISTING_DESCRIPTION_LENGTH = 220;

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
                // magnifier's page shows the untrimmed description. Plain-text cut on purpose:
                // shorten_text() is HTML-aware and mangles plain text containing '<' or '>'
                // (tokenises them as tags and appends fabricated closers - final-review finding),
                // so a character cut runs first and escaping runs last, on the short string.
                $short = \core_text::strlen($description) > self::LISTING_DESCRIPTION_LENGTH
                    ? \core_text::substr($description, 0, self::LISTING_DESCRIPTION_LENGTH) . '…'
                    : $description;
                $html .= html_writer::tag('p', s($short));
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

    /**
     * Return a rule name escaped for safe HTML output.
     *
     * The sibling of escaped_description(), for the other user-written field. It exists as a
     * method rather than a line in each page because the pages that emit a rule name are page
     * scripts, which no unit test can load: putting the decision here is what makes it testable
     * at all. Both callers - the rules list and the delete confirmation - go through it, so the
     * name is escaped the same way on every screen that shows it.
     *
     * format_string() rather than s(): PARAM_TEXT, which types the field on the form, keeps valid
     * multilang markup (lib/classes/param.php:891 returns before the final strip_tags), so s()
     * would print that markup on screen instead of resolving it. The guarantee that holds on any
     * site, with $CFG->formatstringstriptags on or off, is that the result carries no executable
     * HTML: on it strips tags and escapes what remains, off it runs clean_text()
     * (lib/classes/formatting.php:119-130).
     *
     * The form is not the only writer. Course restore inserts the name with no cleaning at all
     * (restore_local_coursedynamicrules_plugin.class.php:95), so a rule arriving from a crafted
     * backup carries whatever its author typed - which is why escaping at the point of output is
     * the only place it can be done once and be true everywhere.
     *
     * @param string|null $name The stored rule name.
     * @param \context $context Course context the filters run in.
     * @return string
     */
    public static function escaped_name(?string $name, \context $context): string {
        return format_string((string) $name, true, ['context' => $context]);
    }
}
