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

\local_coursedynamicrules\helper\rule_duplicator::duplicate($id, $courseid, $context);

redirect(
    new moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $courseid]),
    get_string('ruleduplicated', 'local_coursedynamicrules'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
