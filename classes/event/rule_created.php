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
 * Event triggered when a dynamic rule is created.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_created extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_coursedynamicrules_rule';
    }

    /**
     * Return the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:rule_created', 'local_coursedynamicrules');
    }

    /**
     * Return a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' created the dynamic rule with id '{$this->objectid}' " .
            "in the course with id '{$this->courseid}'.";
    }

    /**
     * Return the URL relevant to the event.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $this->courseid]);
    }
}
