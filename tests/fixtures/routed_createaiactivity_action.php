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

namespace local_coursedynamicrules\action\routedcreateaiactivity;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/testable_createaiactivity_action.php');

use local_coursedynamicrules\action\createaiactivity\testable_createaiactivity_action;

/**
 * The testable AI action under an action type the rule engine can load by name.
 *
 * rule_component_loader builds an action class from the stored action type, so an integration
 * test that drives the real observer and adhoc task stores the type "routedcreateaiactivity" and
 * gets this class: the production execute() with the testable seams (client double, canned stream).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class routedcreateaiactivity_action extends testable_createaiactivity_action {
}
