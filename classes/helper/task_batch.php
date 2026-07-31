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
 * Operational batch size for the scheduled tasks that scan enrolled users.
 *
 * The evaluation tasks run frequently and iterate every enrolled user of every course with an
 * active rule. This value is a configurable threshold used for observability: tasks report when a
 * course exceeds it so large installations can be tuned. It can be overridden with
 * $CFG->forced_plugin_settings or set_config('taskbatchsize', N, 'local_coursedynamicrules').
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_batch {
    /** @var int Default batch size when nothing is configured. */
    const DEFAULT_SIZE = 500;

    /**
     * The configured batch size, or the default, never below one.
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
