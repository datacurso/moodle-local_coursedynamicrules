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

/**
 * Every string the privacy provider uses as an export folder name fits in 100 characters, in every language.
 *
 * The export writer shortens a folder name longer than 100 characters with code that needs
 * lib/filelib.php, which the tool_dataprivacy export task does not load: a real export in a language
 * whose folder name was too long failed with "Call to undefined function get_mimetypes_array()" and
 * finished without the data. PHPUnit loads filelib.php, so only the length itself can be tested.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class export_folder_names_test extends \basic_testcase {
    /** @var string[] The string keys provider.php passes to writer::export_data() as subcontext names. */
    private const FOLDER_KEYS = [
        'privacy:export:activityaccess',
        'privacy:export:airequests',
        'privacy:export:restorenote',
        'privacy:remotedata',
    ];

    /** @var int The longest folder name the export writer keeps without shortening it. */
    private const MAX_LENGTH = 100;

    /**
     * Each shipped language pack keeps every export folder name within 100 characters.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_every_export_folder_name_fits_in_every_language(): void {
        $packs = glob(__DIR__ . '/../../lang/*/local_coursedynamicrules.php');
        $this->assertNotEmpty($packs, 'No language pack found.');

        $toolong = [];
        foreach ($packs as $file) {
            $string = [];
            include($file);
            $lang = basename(dirname($file));
            foreach (self::FOLDER_KEYS as $key) {
                if (!isset($string[$key])) {
                    continue;
                }
                $length = \core_text::strlen($string[$key]);
                if ($length > self::MAX_LENGTH) {
                    $toolong[] = "$lang:$key ($length)";
                }
            }
        }

        $this->assertSame([], $toolong, 'Export folder names longer than ' . self::MAX_LENGTH . ' characters.');
    }

    /**
     * The keys listed here are the ones provider.php really uses as folder names.
     *
     * @return void
     */
    public function test_the_listed_keys_are_the_folder_names_the_provider_uses(): void {
        $source = (string) file_get_contents(__DIR__ . '/../../classes/privacy/provider.php');
        preg_match_all("/\\[get_string\\('([^']+)', 'local_coursedynamicrules'\\)\\]/", $source, $matches);
        $used = array_values(array_unique($matches[1]));
        sort($used);

        $this->assertSame(self::FOLDER_KEYS, $used);
    }
}
