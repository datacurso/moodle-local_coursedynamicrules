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

namespace local_coursedynamicrules\task;

use local_coursedynamicrules\local\restore_reconciliation;

/**
 * Reconciles, once, the gates a restore of a pre-fingerprint backup could only deduce.
 *
 * Queued by the restore of a backup that records no gate fingerprint (made before 1.8.13), for the
 * actions it created, and by the 2026112001 upgrade step for every course that still carries a
 * deduced marker. What it decides is described in restore_reconciliation (CDR-PRIV-002-R2). It runs
 * whether or not the AI provider is enabled: it only reads and marks this plugin's own restrictions.
 *
 * Custom data: courseid (int), actionids (int[]|null). A course-wide run (actionids null, as the
 * upgrade queues it) promotes and notes nothing beyond the nodes a restore deduced.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_reconciliation_task extends \core\task\adhoc_task {
    /**
     * Queue the reconciliation of a course.
     *
     * @param int $courseid The course.
     * @param int[]|null $actionids Only these actions, or null for every enable-activity action of the course.
     * @return void
     */
    public static function queue(int $courseid, ?array $actionids = null): void {
        $task = new self();
        $task->set_custom_data([
            'courseid' => $courseid,
            'actionids' => $actionids === null ? null : array_values(array_map('intval', $actionids)),
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue the reconciliation of every course with a marker a restore only deduced.
     *
     * The JSON substring only narrows the scan; the reconciliation decides on the decoded tree.
     *
     * @return void
     */
    public static function queue_for_adopted_courses(): void {
        global $DB;

        $like = $DB->sql_like('availability', ':adopted');
        $courseids = $DB->get_fieldset_select(
            'course_modules',
            'DISTINCT course',
            $like,
            ['adopted' => '%' . $DB->sql_like_escape('"sourceadopted"') . '%']
        );
        foreach ($courseids as $courseid) {
            self::queue((int) $courseid);
        }
    }

    /**
     * Run the reconciliation.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }
        $actionids = isset($data->actionids) && is_array($data->actionids) ? array_map('intval', $data->actionids) : null;
        $attributed = restore_reconciliation::run($courseid, $actionids);
        mtrace('local_coursedynamicrules: restore reconciliation of course ' . $courseid . ': '
            . $attributed . ' restriction(s) attributed.');
    }
}
