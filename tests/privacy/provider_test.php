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
 * Tests for the privacy provider.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \advanced_testcase {
    /**
     * The plugin declares the external transfer of course data to the Datacurso AI service.
     */
    public function test_get_metadata_declares_external_ai_transfer(): void {
        $collection = new collection('local_coursedynamicrules');

        $result = provider::get_metadata($collection);

        $this->assertInstanceOf(collection::class, $result);

        $items = $result->get_collection();
        $this->assertNotEmpty($items);

        $names = array_map(fn($item) => $item->get_name(), $items);
        $this->assertContains('datacurso_ai', $names);

        // Every declared field and summary must resolve to a real language string.
        foreach ($items as $item) {
            $this->assertNotEmpty(get_string($item->get_summary(), 'local_coursedynamicrules'));
            foreach ($item->get_privacy_fields() as $field => $identifier) {
                $this->assertNotEmpty(get_string($identifier, 'local_coursedynamicrules'));
            }
        }
    }
}
