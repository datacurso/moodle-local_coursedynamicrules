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
 * A remote erasure client that records every call and answers as told.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_remote_erasure_client implements remote_erasure_client {
    /** @var array[] Every call, as ['serviceid' => string, 'userid' => int, 'threadids' => string[]]. */
    public array $calls = [];

    /**
     * Build the fake.
     *
     * @param bool $succeeds Whether the service confirms the erasure.
     */
    public function __construct(
        /** @var bool Whether the service confirms the erasure. */
        public bool $succeeds = true
    ) {
    }

    #[\Override]
    public function erase(string $serviceid, int $userid, array $threadids): bool {
        $this->calls[] = ['serviceid' => $serviceid, 'userid' => $userid, 'threadids' => $threadids];

        return $this->succeeds;
    }
}
