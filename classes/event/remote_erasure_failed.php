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
 * Event triggered when the remote erasure of a student's AI requests keeps failing.
 *
 * Fired once, when the consecutive failed attempts to erase (and verify the erasure of) a student's
 * requests on the course service reach remote_erasure::FAILURE_THRESHOLD; later failures of the same
 * rows do not fire it again. The student is the related user; the event carries only counts, never a
 * thread id or a client request id (CDR-PRIV-001-R2).
 *
 * @property-read array $other {
 *      - int attempts: the consecutive failed attempts.
 *      - int requests: the requests still pending erasure in that attempt.
 * }
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_erasure_failed extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = null;
    }

    /**
     * Return the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:remote_erasure_failed', 'local_coursedynamicrules');
    }

    /**
     * Return a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        $attempts = (int) ($this->other['attempts'] ?? 0);
        $requests = (int) ($this->other['requests'] ?? 0);
        return "The erasure on the course service of {$requests} AI request(s) of the user with id " .
            "'{$this->relateduserid}' failed or could not be verified {$attempts} consecutive times; it stays pending.";
    }

    /**
     * Validate the event data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        foreach (['attempts', 'requests'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * The event is about no object (system context, counts only), so there is no objectid to map.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }

    /**
     * Map the other fields for restore; they are plain counts.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
