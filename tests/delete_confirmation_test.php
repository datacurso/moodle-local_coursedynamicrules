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

namespace local_coursedynamicrules;

/**
 * The three delete confirmations must not share one token between every operator on the site.
 *
 * Each page used to answer "has this been confirmed?" by comparing the request against a single
 * plugin configuration value, written on a GET while the confirmation page rendered. One slot, for
 * the whole site, bound to neither the record being deleted nor the person deleting it.
 *
 * Two teachers in unrelated courses were therefore enough to break it: the second to open a
 * confirmation overwrote the first one's token, and the first one's Delete button then silently
 * failed the comparison, re-rendered the same question and overwrote the token again. Nothing was
 * deleted and nothing said why. No attacker, two tabs.
 *
 * What actually protects these pages is the session key on the POST, the capability, and the
 * ownership check that binds the record to the course - none of which needs a stored token.
 *
 * What these assertions are, and are not. They read the three page files as text, because a page
 * script cannot be loaded under PHPUnit: it wants $PAGE, a renderer and a header. So they prove the
 * storage is gone from these three files and nothing more - a confirmation written some other way,
 * or through a helper, would slip past them. The behaviour itself, two operators not cancelling
 * each other, is reachable only through Behat and is not covered here. Said plainly rather than
 * implied, so nobody reads a green run as more than it is.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @coversNothing
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_confirmation_test extends \advanced_testcase {
    /** @var string[] The three delete endpoints. */
    private const PAGES = ['deleterule.php', 'deletecondition.php', 'deleteaction.php'];

    /**
     * The source of each delete page.
     *
     * @param string $page File name.
     * @return string
     */
    private function source(string $page): string {
        // Read from this file's own directory, never from $CFG->dirroot: under a bind mount those
        // are the same tree and off it they are not, so a test reading the deployed plugin reports
        // on the deployment rather than on the branch it lives in.
        return (string) file_get_contents(__DIR__ . '/../' . $page);
    }

    /**
     * No delete page stores a confirmation token in the plugin configuration.
     *
     * @return void
     */
    public function test_no_delete_page_stores_a_confirmation_token(): void {
        $this->resetAfterTest(true);

        foreach (self::PAGES as $page) {
            $source = $this->source($page);
            $this->assertStringNotContainsString(
                'set_config(',
                $source,
                "{$page} still writes plugin configuration; a confirmation is a per-request question."
            );
            $this->assertStringNotContainsString(
                'confirmdelete',
                $source,
                "{$page} still carries the shared confirmation token."
            );
        }
    }

    /**
     * Each delete page confirms with the session key, which is per user and per session.
     *
     * @return void
     */
    public function test_each_delete_page_confirms_with_the_session_key(): void {
        $this->resetAfterTest(true);

        foreach (self::PAGES as $page) {
            $source = $this->source($page);
            $this->assertStringContainsString(
                "optional_param('confirm'",
                $source,
                "{$page} does not read an explicit confirmation flag."
            );
        }
    }
}
