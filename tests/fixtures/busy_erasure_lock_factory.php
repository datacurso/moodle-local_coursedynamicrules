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
 * A lock factory whose remote erasure locks are always held by another process.
 *
 * Every other lock is delegated to the database record lock factory.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class busy_erasure_lock_factory implements \core\lock\lock_factory {
    /** @var \core\lock\db_record_lock_factory The factory every other lock goes to. */
    private \core\lock\db_record_lock_factory $inner;

    /**
     * Build the factory.
     *
     * @param string $type The lock type.
     */
    public function __construct($type) {
        $this->inner = new \core\lock\db_record_lock_factory($type);
    }

    #[\Override]
    public function supports_timeout() {
        return $this->inner->supports_timeout();
    }

    #[\Override]
    public function supports_auto_release() {
        return $this->inner->supports_auto_release();
    }

    #[\Override]
    public function is_available() {
        return true;
    }

    #[\Override]
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (strpos((string) $resource, 'remote_erasure_') === 0) {
            return false;
        }
        return $this->inner->get_lock($resource, $timeout, $maxlifetime);
    }

    #[\Override]
    public function release_lock(\core\lock\lock $lock) {
        return $this->inner->release_lock($lock);
    }
}
