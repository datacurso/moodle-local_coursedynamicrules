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

/**
 * The one door the listing pages' capability decisions go through.
 *
 * The decisions used to live inline in rules.php, conditions.php and actions.php - and inline in a
 * page script means untestable: a page cannot be loaded from PHPUnit, and the acceptance runner
 * fails any scenario that lands on an exception page, so nothing red happened when the checks were
 * deleted. A blind review proved exactly that. Behaviour is unchanged; what changed is that the
 * decision now has an address that a test with real roles can call, and the pages' wiring to it is
 * pinned by its own test.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_gate {
    /**
     * Entering a component listing requires BOTH halves of its pair.
     *
     * manage* was historically the only check; view* joined it in 1.8.3, and the changelog warns
     * custom-role administrators about exactly this pair. The order is fixed - view first - so the
     * error a doubly-lacking role sees names the reading permission, not the writing one.
     *
     * @param string $component One of 'rule', 'condition', 'action'.
     * @param \context $context The course context the pages run in.
     * @return void
     * @throws \required_capability_exception If either half is missing.
     */
    public static function require_listing(string $component, \context $context): void {
        require_capability('local/coursedynamicrules:view' . $component, $context);
        require_capability('local/coursedynamicrules:manage' . $component, $context);
    }

    /**
     * Creating a component requires create*, whatever else the role holds.
     *
     * The add menu is only rendered for a role that holds this, but the component type arrives as
     * a URL parameter, and a URL is not a menu.
     *
     * @param string $component One of 'condition', 'action'.
     * @param \context $context The course context.
     * @return void
     * @throws \required_capability_exception If the role may not create.
     */
    public static function require_creation(string $component, \context $context): void {
        require_capability('local/coursedynamicrules:create' . $component, $context);
    }

    /**
     * The same decision for a COMPONENT listing: conditions.php or actions.php when the operator may
     * enter it, and otherwise whatever listing_url() allows - the rules listing, or the course page.
     *
     * Deleting a component demands only deletecondition/deleteaction, and each component listing
     * demands its own view+manage pair, so the two delete pages could hand the operator a Continue
     * button - and a Cancel link - into a guaranteed refusal, after the deletion was already done.
     * Same seam as listing_url(), one door along.
     *
     * @param string $component 'condition' or 'action'.
     * @param int $courseid
     * @param int $ruleid The rule whose components were being listed.
     * @param \context $context The course context.
     * @return \moodle_url
     */
    public static function component_listing_url(
        string $component,
        int $courseid,
        int $ruleid,
        \context $context
    ): \moodle_url {
        $canseelisting = has_capability('local/coursedynamicrules:view' . $component, $context)
            && has_capability('local/coursedynamicrules:manage' . $component, $context);
        if (!$canseelisting) {
            return self::listing_url($courseid, $context);
        }

        return new \moodle_url(
            '/local/coursedynamicrules/' . $component . 's.php',
            ['courseid' => $courseid, 'ruleid' => $ruleid]
        );
    }

    /**
     * Where a page sends the operator when it is done: the rules listing when they may enter it,
     * the course page otherwise.
     *
     * The listing demands view+manage rule (require_listing above), and several pages are reachable
     * by a role that does NOT hold that pair - the component pages demand the CONDITION or ACTION
     * pair, and the write endpoints demand only their own create/delete capability. Sending such a
     * role to the listing means the work is done and then an error is shown, or a "back" link that
     * is a live link into a guaranteed refusal. editrule.php found this first and fixed it inline;
     * the decision lives here so no page can drift from it.
     *
     * @param int $courseid The course whose listing is the natural destination.
     * @param \context $context The course context.
     * @return \moodle_url The listing, or the course page.
     */
    public static function listing_url(int $courseid, \context $context): \moodle_url {
        $canseelisting = has_capability('local/coursedynamicrules:viewrule', $context)
            && has_capability('local/coursedynamicrules:managerule', $context);

        return $canseelisting
            ? new \moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $courseid])
            : new \moodle_url('/course/view.php', ['id' => $courseid]);
    }
}
