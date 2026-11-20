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
 * The remoteerasurependingdays setting: whole days, at least one (internal security review, 2026-11-20).
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_erasure_days_setting extends \admin_setting_configtext {
    /**
     * Build the setting.
     */
    public function __construct() {
        parent::__construct(
            'local_coursedynamicrules/remoteerasurependingdays',
            new \lang_string('remoteerasurependingdays', 'local_coursedynamicrules'),
            new \lang_string('remoteerasurependingdays_desc', 'local_coursedynamicrules'),
            remote_erasure::DEFAULT_PENDING_DAYS,
            PARAM_INT
        );
    }

    #[\Override]
    public function validate($data) {
        if (!preg_match('/^\d+$/', trim((string) $data)) || (int) $data < 1) {
            return get_string('remoteerasurependingdays_invalid', 'local_coursedynamicrules');
        }

        return true;
    }
}
