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
 * Page size for the scheduled tasks that walk enrolled users.
 *
 * The evaluation tasks run frequently and walk every enrolled user of every course with a rule
 * that is due. This value is the number of user ids fetched per query by that walk (see
 * enrolled_users), so it bounds the memory a task holds at any moment; the tasks also report when
 * a course exceeds it. It is administered from Site administration > Plugins > Local plugins >
 * Smart Rules AI (settings.php), and can still be forced from config.php with
 * $CFG->forced_plugin_settings.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_batch {
    /** @var int Default page size when nothing is configured. */
    const DEFAULT_SIZE = 500;

    /**
     * The configured page size, or the default, never below one.
     *
     * @return int
     */
    public static function size(): int {
        $configured = get_config('local_coursedynamicrules', 'taskbatchsize');
        if ($configured === false || $configured === '') {
            return self::DEFAULT_SIZE;
        }

        return max(1, (int) $configured);
    }
}
