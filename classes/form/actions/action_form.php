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

namespace local_coursedynamicrules\form\actions;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Class action_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_form extends \moodleform {
    /**
     * Add elements to form.
     */
    public function definition() {
        $this->add_action_buttons();

        // Array_key_exists(), not !empty(): json_decode('[]') decodes to an empty PHP array, which
        // !empty() treats as "no record" even though the key IS present (an edit row whose stored
        // params happen to be empty), silently falling back to create-mode defaults (FIX2-12).
        // is_array() guard: $this->_customdata defaults to null when a caller does not pass any
        // customdata at all - array_key_exists() on null is a TypeError under PHP 8.
        if (is_array($this->_customdata) && array_key_exists('record', $this->_customdata)) {
            $this->set_data($this->preload_defaults($this->_customdata['record']));
        }
    }

    /**
     * Map the stored params object into the array consumed by set_data().
     *
     * Concrete forms may override this to translate stored keys into element names, or to force
     * explicit values for checkbox groups so an edit does not fall back to a setDefault(). Safe to
     * call unconditionally: every concrete form calls parent::definition() last, so all of its own
     * elements already exist by the time this runs.
     *
     * @param object $params Decoded stored params for the component being edited.
     * @return array
     */
    protected function preload_defaults($params): array {
        return (array) $params;
    }
}
