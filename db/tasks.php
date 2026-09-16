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
 * Scheduled task definitions for Smart Rules AI
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/task/scheduled}
 *
 * @package    local_coursedynamicrules
 * @category   task
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Four times an hour, not sixty. The cadence never decided when a rule fires - each rule keeps
    // its own clock, adding its own period to reach its next due time - so it only decides how long
    // a rule waits after falling due. The finest window either condition offers an operator is the
    // hour, so a quarter of an hour of slack is invisible against the smallest thing anyone can
    // configure, and it is ninety-six times fewer passes over every enrolled user of every course
    // with an active rule.
    [
        'classname' => 'local_coursedynamicrules\task\no_complete_activity_task',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => 'local_coursedynamicrules\task\no_course_access_task',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => 'local_coursedynamicrules\task\course_inactivity_task',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '*/6',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
        'disabled' => 0,
    ],
];
