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

namespace local_coursedynamicrules\form\conditions;

/**
 * Minimal concrete condition_form used to exercise the base preload hook.
 *
 * Adds one field ('foo') before delegating to parent::definition(), mirroring how every real
 * per-type form adds its own fields and calls parent::definition() last.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stub_preload_condition_form extends condition_form {
    /** @var string type of condition */
    protected $type = 'stub';

    /**
     * @return void
     */
    public function definition() {
        $this->_form->addElement('text', 'foo', 'Foo');
        $this->_form->setType('foo', PARAM_TEXT);
        parent::definition();
    }
}
