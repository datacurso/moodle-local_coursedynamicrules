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

namespace local_coursedynamicrules\helper;

/**
 * Tests for the scheduled-task batch size helper.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\helper\task_batch
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_batch_test extends \advanced_testcase {
    /**
     * The default size applies when nothing is configured.
     */
    public function test_default_size_when_unset(): void {
        $this->resetAfterTest(true);

        $this->assertSame(task_batch::DEFAULT_SIZE, task_batch::size());
    }

    /**
     * A configured positive size is used.
     */
    public function test_configured_size_is_used(): void {
        $this->resetAfterTest(true);

        set_config('taskbatchsize', 250, 'local_coursedynamicrules');

        $this->assertSame(250, task_batch::size());
    }

    /**
     * A non-positive configured size is clamped to at least one.
     */
    public function test_invalid_size_is_clamped_to_minimum(): void {
        $this->resetAfterTest(true);

        set_config('taskbatchsize', 0, 'local_coursedynamicrules');

        $this->assertSame(1, task_batch::size());
    }
}
