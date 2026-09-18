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

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\enrolled_users;
use local_coursedynamicrules\helper\task_batch;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/completionlib.php');

/**
 * Class course_inactivity_task
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_inactivity_task extends \core\task\scheduled_task {
    /** @var string type of condition */
    protected $conditiontype = "course_inactivity";

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('course_inactivity_task', 'local_coursedynamicrules');
    }

    /**
     * Execute the task.
     *
     */
    public function execute() {
        global $DB;

        $conditiontype = $this->conditiontype;
        $starttime = microtime(true);
        $batchsize = task_batch::size();

        // Retrieve all active rules with the specified condition type.
        $rules = $DB->get_records_sql(
            "SELECT DISTINCT r.*
            FROM
                {local_coursedynamicrules_rule} r
                JOIN {local_coursedynamicrules_condition} c ON c.ruleid = r.id
            WHERE
                c.conditiontype = :conditiontype
                AND r.active = 1",
            ['conditiontype' => $conditiontype]
        );

        $executed = 0;
        $totalusers = 0;

        // Iterate through each rule and execute if conditions are met.
        foreach ($rules as $rule) {
            if (!$this->is_time_to_execute($rule)) {
                continue;
            }

            $completion = new \completion_info(get_course($rule->courseid));
            // Active-only enrolled users (excludes suspended and deleted users, one row per user
            // however many enrolments they hold), walked in pages of ids and filtered as they stream:
            // the course is never held in memory whole (see helper\enrolled_users).
            $context = \context_course::instance($rule->courseid);
            $users = enrolled_users::ids($context, $batchsize);

            // Counted only for a rule that runs, for the report and the threshold notice.
            $usercount = count_enrolled_users($context, '', 0, true);
            $totalusers += $usercount;
            if ($usercount > $batchsize) {
                mtrace("local_coursedynamicrules: course {$rule->courseid} has {$usercount} enrolled users "
                    . "(over batch threshold {$batchsize}) while evaluating rule {$rule->id}.");
            }

            $ruleinstance = new rule($rule, self::without_course_completion($users, $completion));
            $ruleinstance->execute();
            $executed++;
        }

        if (!empty($rules)) {
            mtrace(sprintf(
                'local_coursedynamicrules: %s evaluated %d active rules and %d users, executed %d, in %.2fs.',
                $conditiontype,
                count($rules),
                $totalusers,
                $executed,
                microtime(true) - $starttime
            ));
        }
    }

    /**
     * Pass through the users who have not completed the course, without collecting them.
     *
     * @param iterable $users stdClass objects carrying at least "id".
     * @param \completion_info $completion The course's completion info.
     * @return \Generator<int, \stdClass>
     */
    private static function without_course_completion(iterable $users, \completion_info $completion): \Generator {
        foreach ($users as $user) {
            if (!$completion->is_course_complete($user->id)) {
                yield $user;
            }
        }
    }

    /**
     * Check if it is time to execute the rule.
     *
     * @param object $rule Rule object.
     * @return bool True if it is time to execute the rule, false otherwise.
     */
    private function is_time_to_execute($rule) {
        global $DB;
        $courseid = $rule->courseid;
        $now = time();

        $course = get_course($courseid);

        if ($course->enddate && $now > $course->enddate) {
            return false;
        }

        return $now >= $course->startdate;
    }
}
