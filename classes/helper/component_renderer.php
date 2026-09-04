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
     * @var int Characters of a component's FREE TEXT the rules list shows before cutting.
     *
     * Free text means the part a teacher types with no limit: a notification body, an AI prompt,
     * the list of activities an enable action names. Each component cuts its own, in
     * get_listing_description(); this is the shared budget they use, and 80 is what 1.8.2 showed.
     *
     * It is NOT a budget for the composed description, and that distinction is the whole fix. Two
     * earlier attempts cut the finished sentence - first at 80, then at 220 - and both failed the
     * same way: a notification's description opens with "Enviar notificacion '<asunto>' a los
     * usuarios Destinatarios: <roles>. Con copia a: <roles>. Mensaje: ", measured at 120
     * characters with one role and 196 with five in Spanish with default role names, so the budget
     * was spent before the message began. Neither the subject (CHAR 255) nor the role list has an
     * upper bound, so no number over the composed string can both bound the row and guarantee
     * visible text. Bounding each free-text part at its source can.
     */
    public const LISTING_FREETEXT_LENGTH = 80;

    /**
     * Cut a component's free text to the listing budget, on plain text, with an ellipsis.
     *
     * A character cut rather than shorten_text(), which is HTML-aware and mangles plain text
     * containing '<' or '>' - it tokenises them as tags and appends fabricated closers.
     *
     * @param string $text
     * @return string
     */
    public static function cut_freetext(string $text): string {
        return \core_text::strlen($text) > self::LISTING_FREETEXT_LENGTH
            ? \core_text::substr($text, 0, self::LISTING_FREETEXT_LENGTH) . '…'
            : $text;
    }

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
            // The LISTING form, not get_description(): the component decides what of
            // itself is free text and cuts that (product directive 2026-08-31 asked the listing to
            // keep a uniform row height; the magnifier's page still shows everything). Escaping
            // runs last, on the already-short string, so no entity is ever sliced in half.
            $description = $instance->get_listing_description();
            if (!empty($header) && !empty($description)) {
                $html .= html_writer::tag('p', s($description));
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
