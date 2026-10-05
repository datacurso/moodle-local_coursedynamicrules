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

namespace local_coursedynamicrules\core;

/**
 * Minimal concrete action used to exercise the base upsert() helper in isolation.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stub_action extends action {
    /** @var string type of action */
    protected $type = 'stub';

    /**
     * Public wrapper so tests can exercise the protected upsert() helper.
     *
     * @param array $params
     * @param object $formdata
     * @return int
     */
    public function test_upsert(array $params, $formdata) {
        return $this->upsert($params, $formdata);
    }

    /**
     * Stub execution that always succeeds without side effects.
     *
     * @param object $context
     */
    public function execute($context) {
        return true;
    }

    /**
     * No-op stub so tests can instantiate stub_action without a real edit form.
     *
     * @param mixed $action
     * @param mixed $customdata
     * @param string $method
     * @param string $target
     * @param mixed $attributes
     * @param bool $editable
     * @param array $ajaxformdata
     */
    public function build_editform(
        $action = null,
        $customdata = null,
        $method = 'post',
        $target = '',
        $attributes = null,
        $editable = true,
        $ajaxformdata = null
    ) {
    }

    /**
     * No-op stub so tests can instantiate stub_action without persisting real form data.
     *
     * @param object $formdata
     */
    public function save_action($formdata) {
    }
}
