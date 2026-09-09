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

/**
 * Duplicate a rule into an inactive, unsealed draft.
 *
 * The lock's escape hatch, traced from Workplace's dynamic rules: a copy is harmless (born
 * inactive, never sealed), so no confirmation page - sesskey and the create capability guard it,
 * and ownership binds the source to this course inside the duplicator.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
// Duplicating creates a rule: the create capability is the one that speaks here.
require_capability('local/coursedynamicrules:createrule', $context);
require_sesskey();

// It also creates the source's components, so it takes the very capabilities adding them by hand
// takes (page_gate::require_creation, enforced by conditions.php/actions.php). Without this, a role
// allowed to create rules but not components could produce components through the copy control that
// it cannot create at all - the "a URL is not a menu" gap page_gate exists to close, one door along.
// Ownership speaks first, so the component counts of another course's rule never leak, and only the
// halves the copy will actually create are demanded: an empty rule copies for a create-rule role.
$source = \local_coursedynamicrules\helper\ownership::get_rule($id, $courseid);
if ($DB->record_exists('local_coursedynamicrules_condition', ['ruleid' => $source->id])) {
    \local_coursedynamicrules\helper\page_gate::require_creation('condition', $context);
}
if ($DB->record_exists('local_coursedynamicrules_action', ['ruleid' => $source->id])) {
    \local_coursedynamicrules\helper\page_gate::require_creation('action', $context);
}

// Where to land afterwards, mirroring editrule.php: the listing needs viewrule AND managerule,
// so a role that may only create would be redirected into a permission error AFTER the copy was
// written - work done, error shown. Such a role lands on the course page instead, same message.
$returnurl = \local_coursedynamicrules\helper\page_gate::listing_url($courseid, $context);

\local_coursedynamicrules\helper\rule_duplicator::duplicate($id, $courseid, $context);

redirect(
    $returnurl,
    get_string('ruleduplicated', 'local_coursedynamicrules'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
