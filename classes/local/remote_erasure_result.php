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

namespace local_coursedynamicrules\local;

/**
 * What the course service answered to one erasure request (remote_erasure_client::erase()).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote_erasure_result {
    /**
     * Build the result.
     *
     * @param bool $confirmed Whether every call answered {"status": "erased"}.
     * @param bool $keysunderstood Whether every answer carried erased_thread_ids, the field only a service
     *     that understands client_request_ids returns. False means the keys may have been ignored.
     */
    public function __construct(
        /** @var bool Whether every call answered {"status": "erased"}. */
        public readonly bool $confirmed,
        /** @var bool Whether every answer carried erased_thread_ids. */
        public readonly bool $keysunderstood = false
    ) {
    }
}
