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
 * Event observers for Smart Rules AI
 *
 * @package    local_coursedynamicrules
 * @category   event
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_coursedynamicrules\observer\course_module_completion_updated::observe',
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\local_coursedynamicrules\observer\user_graded::observe',
    ],
    [
        // A student who enrols AFTER a reinforcement was generated has no grade_grades row for it,
        // so the empty column counts against them under every method that treats empty as zero.
        // Nothing else notices: the activity is not new, nobody is graded, no rule runs.
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_coursedynamicrules\observer\user_enrolled::observe',
    ],
    [
        // The path a suspended or expired enrolment takes back into the gradebook. Observing only
        // the creation event left those students counted by aggregation and shielded by nobody.
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\local_coursedynamicrules\observer\user_enrolled::observe_updated',
    ],
    [
        // The gradebook counts a student only once they hold a gradebook role, and that is a
        // separate event from the enrolment. Without this, the sweep skipped every student
        // enrolled by a plugin that assigns the role a moment later - including the ordinary path.
        'eventname' => '\core\event\role_assigned',
        'callback' => '\local_coursedynamicrules\observer\user_enrolled::observe_role',
    ],
    [
        // Under "no grade" a compliant module creates no grade item, so there is nothing to
        // exclude anybody from - until a teacher sets a maximum grade and the column appears.
        // This shields everybody at that moment. It writes grade_grades rows and never touches
        // the item, which is what makes it safe inside a grade-item event.
        'eventname' => '\core\event\grade_item_created',
        'callback' => '\local_coursedynamicrules\observer\grade_item_created::observe',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_coursedynamicrules\observer\course_deleted::observe',
    ],
];
