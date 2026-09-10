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

namespace local_coursedynamicrules\event;

/**
 * What every event this plugin fires owes core, checked over all of them at once.
 *
 * The class list is read from disk, so a new event class joins these checks by existing rather than
 * by somebody remembering to add it - which is how all ten came to share the same gap twice.
 *
 * Course logs are backed up and restored with the course. On restore, tool_log asks the event class
 * how to translate the stored objectid into the destination course's own id
 * (admin/tool/log/backup/moodle2/restore_tool_log_logstore_subplugin.class.php:97-111). An event
 * that does not answer leaves the id pointing at a row in the SOURCE site, so the restored log entry
 * refers to a rule that either does not exist here or, worse, is somebody else's.
 *
 * The plugin's restore step already registers mappings for its three tables
 * (backup/moodle2/restore_local_coursedynamicrules_plugin.class.php), so the right answer here is a
 * real mapping, not core\event\base::NOT_MAPPED.
 *
 * get_other_mapping() is deliberately NOT required: the restore step consults it only for events
 * carrying data in 'other' (same file, line 118), and none of this plugin's events do.
 *
 * @package    local_coursedynamicrules
 * @covers     \local_coursedynamicrules\event\rule_autodeactivated::get_objectid_mapping
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event_contract_test extends \advanced_testcase {
    /**
     * Every event class the plugin ships, read from disk so a new one joins this test by existing.
     *
     * @return string[] Fully qualified class names.
     */
    private function event_classes(): array {
        $classes = [];
        foreach (glob(__DIR__ . '/../../classes/event/*.php') as $file) {
            $classes[] = '\\local_coursedynamicrules\\event\\' . basename($file, '.php');
        }
        sort($classes);

        return $classes;
    }

    /**
     * Each event answers with the table its objectid lives in, so a restored log points at the
     * destination course's row instead of the source site's.
     */
    public function test_every_event_maps_its_objectid_for_restore(): void {
        $this->resetAfterTest(true);

        $classes = $this->event_classes();
        $this->assertNotEmpty($classes, 'Precondition: the plugin ships event classes to check.');

        foreach ($classes as $class) {
            $objecttable = $class::get_static_info()['objecttable'];
            $this->assertNotEmpty($objecttable, "{$class} declares an objecttable.");

            $mapping = $class::get_objectid_mapping();
            $this->assertDebuggingNotCalled(
                "{$class} must define get_objectid_mapping(); the base class only warns and gives up."
            );

            $this->assertIsArray($mapping, "{$class} must answer with a mapping array.");
            $this->assertSame($objecttable, $mapping['db'] ?? null, "{$class} names its own table under 'db'.");
            $this->assertSame(
                $objecttable,
                $mapping['restore'] ?? null,
                "{$class} points at the restore mapping registered for that table."
            );
        }
    }

    /**
     * And the name each event answers with must be one the restore step actually registers - a
     * mapping nobody registers resolves to NOT_FOUND just as silently as no mapping at all.
     *
     * This one is a coupling guard rather than a red-first test: it pins the two files together so
     * renaming a mapping on one side fails here instead of at somebody's restore.
     */
    public function test_the_restore_step_registers_a_mapping_for_every_table_the_events_name(): void {
        $this->resetAfterTest(true);

        $restore = file_get_contents(
            __DIR__ . '/../../backup/moodle2/restore_local_coursedynamicrules_plugin.class.php'
        );
        $this->assertNotEmpty($restore, 'Precondition: the restore step is readable.');

        foreach ($this->event_classes() as $class) {
            $table = $class::get_static_info()['objecttable'];
            $this->assertStringContainsString(
                "set_mapping('{$table}'",
                $restore,
                "The restore step registers no mapping named '{$table}', which {$class} points at."
            );
        }
    }

    /**
     * Each event resolves its own name, so the log report never shows a raw string key.
     *
     * A missing key is not an empty string: get_string() returns "[[the.key]]" and warns
     * (lib/classes/string_manager_standard.php:355-358). Asserting the name is merely non-empty
     * therefore proves nothing on its own - it is the warning that fails such a test, incidentally.
     * This asserts the thing itself.
     *
     * get_url() is not checked here: it needs a triggered instance, and the events that have one
     * assert it in their own tests.
     */
    public function test_every_event_resolves_its_name(): void {
        $this->resetAfterTest(true);

        foreach ($this->event_classes() as $class) {
            $name = $class::get_name();
            $this->assertDebuggingNotCalled("{$class}::get_name() must resolve a real language string.");
            $this->assertNotEmpty($name, "{$class} must have a name.");
            $this->assertStringNotContainsString(
                '[[',
                $name,
                "{$class} names itself with a language key that does not exist."
            );
        }
    }
}
