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

namespace local_coursedynamicrules\event;

/**
 * Event triggered when the engine switches a rule off after running it.
 *
 * This is the only state change the plugin writes without a person asking for it: a one-shot
 * scheduled rule deactivates itself right after its task executes. It has its own type rather than
 * reusing rule_updated precisely so the logs can answer "who stopped this rule?" - an engine write
 * and a teacher's edit are the two answers that must not look alike.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_autodeactivated extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_coursedynamicrules_rule';
    }

    /**
     * Return the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:rule_autodeactivated', 'local_coursedynamicrules');
    }

    /**
     * Return a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        // Says what the write knows: the row went from active to inactive and no editing screen
        // did it. Naming the scheduled task, or claiming the rule had just run, would assert two
        // things set_active() never checks.
        return "The dynamic rule with id '{$this->objectid}' in the course with id " .
            "'{$this->courseid}' was switched off automatically.";
    }

    /**
     * Return the URL relevant to the event.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $this->courseid]);
    }

    /**
     * Map the objectid so a restored log entry points at this course's own rule.
     *
     * Without this, tool_log leaves the id pointing at a row in the SOURCE site
     * (admin/tool/log/backup/moodle2/restore_tool_log_logstore_subplugin.class.php:97-111). The
     * plugin's restore step registers this mapping when it recreates the rule, so the id can be
     * translated rather than given up on with NOT_MAPPED.
     *
     * NOTE, for this event only: in practice a restore never reaches that code for these rows. The
     * same class remaps the actor first and drops the whole record when it cannot (same file, lines
     * 65-70), and this event's actor is USER_OTHER (-1), which no restore step maps. So an
     * autodeactivation entry does not survive a course copy. That is core's behaviour for any
     * system-attributed event - core's own grade engine pays the same price at
     * lib/grade/grade_item.php:885 - and the alternative is worse: the only way to make the row
     * survive is to name a person, and no person did this. The audit trail is worth more accurate
     * than durable. The mapping stays declared so the answer is right if that path ever changes.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_coursedynamicrules_rule', 'restore' => 'local_coursedynamicrules_rule'];
    }
}
