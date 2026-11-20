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

namespace local_coursedynamicrules\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

use local_coursedynamicrules\local\remote_erasure;

/**
 * Read-only admin setting listing the remote erasures pending longer than remoteerasurependingdays.
 *
 * The list is built when the settings page is rendered, never while the admin tree is loaded, so no
 * other admin page pays for its query (CDR-PRIV-001-R2).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_erasure_pending_setting extends \admin_setting_description {
    /**
     * Build the setting.
     */
    public function __construct() {
        parent::__construct(
            'local_coursedynamicrules/remoteerasurepending',
            new \lang_string('remoteerasurepending', 'local_coursedynamicrules'),
            ''
        );
    }

    /**
     * The data the template renders.
     *
     * @return array
     */
    public function export_for_template(): array {
        $days = remote_erasure::pending_days();
        $listed = remote_erasure::overdue_pending($days);
        $count = remote_erasure::count_overdue_pending($days);
        $format = get_string('strftimedatetimeshort', 'langconfig');

        return [
            'description' => get_string('remoteerasurepending_desc', 'local_coursedynamicrules', $days),
            'hasitems' => $count > 0,
            'count' => $count,
            'items' => array_map(fn(\stdClass $item): array => [
                'userid' => (int) $item->userid,
                'requests' => (int) $item->requests,
                'firstrequested' => userdate((int) $item->firstrequested, $format),
                'attempts' => (int) $item->attempts,
                'lastfailure' => $item->lastfailure === null ? '' : userdate((int) $item->lastfailure, $format),
            ], $listed),
            'more' => max(0, $count - count($listed)),
        ];
    }

    #[\Override]
    public function output_html($data, $query = '') {
        global $OUTPUT;
        $this->description = $OUTPUT->render_from_template(
            'local_coursedynamicrules/remote_erasure_pending',
            $this->export_for_template()
        );

        return parent::output_html($data, $query);
    }
}
