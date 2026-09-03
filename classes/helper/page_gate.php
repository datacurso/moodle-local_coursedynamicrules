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
}
