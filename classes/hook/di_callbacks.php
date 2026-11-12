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

namespace local_coursedynamicrules\hook;

use local_coursedynamicrules\local\course_service_remote_erasure_client;
use local_coursedynamicrules\local\course_service_remote_export_client;
use local_coursedynamicrules\local\remote_erasure_client;
use local_coursedynamicrules\local\remote_export_client;

/**
 * Dependency injection definitions of this plugin.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class di_callbacks {
    /**
     * Register the production remote erasure and export clients.
     *
     * @param \core\hook\di_configuration $hook The DI configuration hook.
     * @return void
     */
    public static function provide_di_configuration(\core\hook\di_configuration $hook): void {
        $hook->add_definition(
            remote_erasure_client::class,
            static fn(): remote_erasure_client => new course_service_remote_erasure_client()
        );
        $hook->add_definition(
            remote_export_client::class,
            static fn(): remote_export_client => new course_service_remote_export_client()
        );
    }
}
