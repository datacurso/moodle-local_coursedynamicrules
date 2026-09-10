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
 * Tests for the create-AI-activity form's prompt placeholder help.
 *
 * MDL-E2E-009: each form must advertise the markers the action behind it actually substitutes. Both
 * action forms render the same placeholder template, and the template hardcoded one list, so the AI
 * form offered a marker its action does not know: the teacher copies it, and the literal text
 * reaches the AI prompt unsubstituted.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\form\actions\createaiactivity_form
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class createaiactivity_form_test extends \advanced_testcase {
    /**
     * Load the testable subclasses used to reach the mform by inheritance.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../../fixtures/testable_createaiactivity_form.php');
        require_once(__DIR__ . '/../../fixtures/testable_sendnotification_form.php');
    }

    /**
     * Return the placeholder help this form renders.
     *
     * @param \moodleform $form A form whose definition() added the placeholder element.
     * @param string $element Name of the static element carrying the help.
     * @return string Rendered HTML.
     */
    private function placeholder_help(\moodleform $form, string $element): string {
        $mform = $form->get_mform_for_test();
        $this->assertTrue(
            $mform->elementExists($element),
            "Precondition: the form added its {$element} element. If it did not, definition() "
                . 'returned early because a required plugin is missing, and this test measures nothing.'
        );

        return $mform->getElement($element)->toHtml();
    }

    /**
     * The AI form advertises the course marker its own action substitutes, and not the other one.
     *
     * createaiactivity_action substitutes {$a->courseurl} - a bare URL, which is what belongs in a
     * prompt - while sendnotification_action substitutes {$a->courselink}, an HTML anchor, which
     * belongs in a message body. Offering courselink on the AI form sends the literal marker text
     * to the model.
     *
     * @covers ::definition
     */
    public function test_the_ai_form_advertises_the_marker_its_own_action_substitutes(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $form = new testable_createaiactivity_form(null, ['courseid' => $course->id, 'ruleid' => 1]);
        $help = $this->placeholder_help($form, 'message_placeholders');

        $this->assertStringContainsString(
            '{$a->courseurl}',
            $help,
            'The AI form must offer the marker its action replaces.'
        );
        $this->assertStringNotContainsString(
            '{$a->courselink}',
            $help,
            'It must not offer a marker its action leaves as literal text in the prompt.'
        );
    }

    /**
     * And the notification form keeps advertising ITS marker: the point is one list per form, not one
     * list swapped for another.
     *
     * @covers \local_coursedynamicrules\form\actions\sendnotification_form::definition
     */
    public function test_the_notification_form_keeps_its_own_course_marker(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $form = new testable_sendnotification_form(null, ['courseid' => $course->id, 'ruleid' => 1]);
        $help = $this->placeholder_help($form, 'messagebody_static');

        $this->assertStringContainsString(
            '{$a->courselink}',
            $help,
            'The notification form must keep offering the anchor marker its action replaces.'
        );
        $this->assertStringNotContainsString(
            '{$a->courseurl}',
            $help,
            'And not the bare-URL marker, which its action does not replace.'
        );
    }
}
