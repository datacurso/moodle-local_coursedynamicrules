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

/**
 * Tests for the action_form base preload hook.
 *
 * @package    local_coursedynamicrules
 * @coversDefaultClass \local_coursedynamicrules\form\actions\action_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class action_form_test extends \advanced_testcase {
    /**
     * Load the stub_preload_action_form fixture used to exercise the base preload hook.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../../fixtures/stub_preload_action_form.php');
    }

    /**
     * preload_defaults() passes through the given params object as a plain array unchanged.
     *
     * @covers ::preload_defaults
     */
    public function test_preload_defaults_returns_params_as_array(): void {
        $reflection = new \ReflectionClass(action_form::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);

        $result = $method->invoke($form, (object) ['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $result);
    }

    /**
     * The base definition() used to read the dead $this->_customdata['action'] key. Constructing
     * the form without that key must not error.
     *
     * @covers ::definition
     */
    public function test_definition_does_not_read_dead_customdata_key(): void {
        $this->resetAfterTest(true);

        $form = new action_form(new \moodle_url('/local/coursedynamicrules/actions.php'), []);

        $this->assertInstanceOf(action_form::class, $form);
    }

    /**
     * definition() preloads a stored record's values into the form's field defaults.
     *
     * @covers ::definition
     */
    public function test_definition_preloads_stored_record_into_form_defaults(): void {
        $this->resetAfterTest(true);

        $form = new stub_preload_action_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['record' => (object) ['foo' => 'preloaded value']]
        );

        $this->assertSame('preloaded value', $this->get_mform($form)->getElement('foo')->getValue());
    }

    /**
     * definition() leaves the field empty when no stored record is supplied.
     *
     * @covers ::definition
     */
    public function test_definition_without_record_does_not_preload(): void {
        $this->resetAfterTest(true);

        $form = new stub_preload_action_form(
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            []
        );

        $this->assertEmpty($this->get_mform($form)->getElement('foo')->getValue());
    }

    /**
     * definition() used to call array_key_exists('record', $this->_customdata) unconditionally - a
     * TypeError under PHP 8 when a caller constructs the form without passing any customdata at all
     * (moodleform defaults $_customdata to null, not an empty array) (micro-sweep).
     *
     * @covers ::definition
     */
    public function test_definition_does_not_error_with_null_customdata(): void {
        $this->resetAfterTest(true);

        $form = new action_form(new \moodle_url('/local/coursedynamicrules/actions.php'), null);

        $this->assertInstanceOf(action_form::class, $form);
    }

    /**
     * `!empty($this->_customdata['record'])` treated an edit row whose stored params decode to an
     * empty PHP array (json_decode('[]')) as "no record", since empty() is true for [] - silently
     * skipping preload_defaults() even though the 'record' key IS present (FIX2-12).
     *
     * @covers ::definition
     */
    public function test_definition_calls_preload_when_record_key_present_but_empty_array(): void {
        $this->resetAfterTest(true);

        $form = new class (
            new \moodle_url('/local/coursedynamicrules/actions.php'),
            ['record' => []]
        ) extends action_form {
            /**
             * Adds the 'foo' field so preload_defaults() has something to populate.
             */
            public function definition() {
                $this->_form->addElement('text', 'foo', 'Foo');
                $this->_form->setType('foo', PARAM_TEXT);
                parent::definition();
            }

            /**
             * Stub override to prove definition() invoked preload_defaults() despite the empty array.
             *
             * @param object $params
             * @return array
             */
            protected function preload_defaults($params): array {
                return ['foo' => 'preload-ran'];
            }
        };

        $this->assertSame('preload-ran', $this->get_mform($form)->getElement('foo')->getValue());
    }

    /**
     * Reach the protected MoodleQuickForm instance held by a moodleform.
     *
     * @param \moodleform $form
     * @return \MoodleQuickForm
     */
    private function get_mform(\moodleform $form) {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        return $property->getValue($form);
    }
}
