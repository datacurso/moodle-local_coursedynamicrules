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

namespace local_coursedynamicrules\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider for local_coursedynamicrules.
 *
 * The plugin stores no personal data in its own tables (rules, conditions and actions hold course
 * configuration only), so it implements no data store. It does, however, transfer course and user
 * context to an external AI service when the "create AI activity" action runs, which is declared
 * here as an external location so administrators can account for it. User-linked side effects that
 * stay inside Moodle (messages, adhoc tasks, activity availability) live in core subsystems that
 * declare their own privacy metadata.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe the personal data leaving Moodle for external processing.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'userid' => 'privacy:metadata:datacurso_ai:userid',
                'courseid' => 'privacy:metadata:datacurso_ai:courseid',
                'courseurl' => 'privacy:metadata:datacurso_ai:courseurl',
                'prompt' => 'privacy:metadata:datacurso_ai:prompt',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }
}
