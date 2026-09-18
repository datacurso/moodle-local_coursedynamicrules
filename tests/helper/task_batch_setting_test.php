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
 * The page size that bounds the scheduled tasks must be reachable by an administrator.
 *
 * The tasks walk a course's enrolled users in pages of this size, so it is the one number that
 * decides how much work a run holds at once - the knob the audit asks for when it says to add batch
 * limits and document operational thresholds. Until now it existed only in code: the plugin shipped
 * no settings.php at all, so the value could be changed with set_config() or a line in config.php
 * and nowhere else. A threshold an administrator cannot see is not an operational control.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @covers      \local_coursedynamicrules\helper\task_batch
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_batch_setting_test extends \advanced_testcase {
    /**
     * The plugin registers an administration page carrying the page size.
     *
     * @return void
     */
    public function test_the_page_size_is_an_administration_setting(): void {
        global $CFG;
        $this->resetAfterTest(true);
        // The settings file only runs for somebody who holds moodle/site:config, so without this
        // the page is never registered and the assertion below would fail for the wrong reason.
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');

        // The full tree, or locate() has nothing to walk: a partial tree carries no settings.
        $admin = admin_get_root(true, true);
        $page = $admin->locate('local_coursedynamicrules');

        $this->assertNotNull($page, 'The plugin registers no administration page.');
        // An administration page keeps its settings as properties of an object, not as a list.
        $names = array_map(fn($s) => $s->name, (array) ($page->settings ?? new \stdClass()));
        $this->assertContains(
            'taskbatchsize',
            $names,
            'The page size is not offered on the plugin administration page.'
        );
    }

    /**
     * The default offered on that page is the one the helper falls back to.
     *
     * A page announcing a different default from the one that actually applies would document the
     * threshold wrongly, which is worse than not documenting it.
     *
     * @return void
     */
    public function test_the_offered_default_is_the_one_the_helper_uses(): void {
        global $CFG;
        $this->resetAfterTest(true);
        // The settings file only runs for somebody who holds moodle/site:config, so without this
        // the page is never registered and the assertion below would fail for the wrong reason.
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');

        $admin = admin_get_root(true, true);
        $page = $admin->locate('local_coursedynamicrules');
        $this->assertNotNull($page);

        foreach ($page->settings as $setting) {
            if ($setting->name === 'taskbatchsize') {
                $this->assertEquals(task_batch::DEFAULT_SIZE, $setting->defaultsetting);
                return;
            }
        }

        $this->fail('The page size setting was not found on the plugin administration page.');
    }

    /**
     * What an administrator saves on that page is what the tasks then use.
     *
     * @return void
     */
    public function test_what_is_saved_is_what_the_tasks_use(): void {
        $this->resetAfterTest(true);

        set_config('taskbatchsize', 25, 'local_coursedynamicrules');

        $this->assertSame(25, task_batch::size());
    }
}
