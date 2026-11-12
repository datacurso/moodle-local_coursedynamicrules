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
 * A remote export client that records every call and answers as told.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_remote_export_client implements remote_export_client {
    /** @var array[] Every call, as ['serviceid' => string, 'userid' => int, 'threadids' => string[]]. */
    public array $calls = [];

    /**
     * Build the fake.
     *
     * @param array|null $data What the service answers; null when it could not be retrieved.
     * @param bool $throws Whether the call throws instead of answering.
     */
    public function __construct(
        /** @var array|null What the service answers; null when it could not be retrieved. */
        public ?array $data = null,
        /** @var bool Whether the call throws instead of answering. */
        public bool $throws = false
    ) {
    }

    #[\Override]
    public function export(string $serviceid, int $userid, array $threadids): ?array {
        $this->calls[] = ['serviceid' => $serviceid, 'userid' => $userid, 'threadids' => $threadids];
        if ($this->throws) {
            throw new \moodle_exception('error');
        }

        return $this->data;
    }
}
