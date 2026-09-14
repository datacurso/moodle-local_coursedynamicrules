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

namespace local_coursedynamicrules\local;

/**
 * Tests for the AI payload anonymizer.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\payload_anonymizer
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class payload_anonymizer_test extends \advanced_testcase {
    /**
     * Create the student whose name the payload may mention.
     *
     * @return \stdClass
     */
    private function create_student(): \stdClass {
        return $this->getDataGenerator()->create_user([
            'firstname' => 'Eva',
            'lastname' => 'Pérez',
        ]);
    }

    /** @var string A byte sequence that is not valid UTF-8: a lone continuation byte. */
    private const INVALID_UTF8 = "\xB1";

    /**
     * A stray invalid byte anywhere in the text must not let the student's name through.
     *
     * The replacement runs a Unicode-aware pattern, and preg_replace() returns null - not a string -
     * when the subject is not valid UTF-8. Keeping the original text in that case means the payload
     * that leaves the site still carries the real name, which is the one outcome this class exists
     * to prevent. Invalid bytes reach a payload through the teacher's own instructions, through a
     * profile field filled by an external sync, or through anything pasted from another system.
     *
     * @return void
     */
    public function test_an_invalid_byte_in_the_text_does_not_let_the_name_through(): void {
        $this->resetAfterTest(true);
        $student = $this->create_student();

        $payload = [
            'message' => 'Refuerzo para Eva' . self::INVALID_UTF8 . ' sobre fracciones.',
            'instructions' => 'Dirigite a Eva Pérez con cercanía.' . self::INVALID_UTF8,
        ];

        $result = payload_anonymizer::anonymize($payload, $student);

        $this->assertStringNotContainsString('Eva', $result['payload']['message']);
        $this->assertStringNotContainsString('Eva', $result['payload']['instructions']);
        $this->assertStringNotContainsString('Pérez', $result['payload']['instructions']);
        $this->assertStringContainsString('[STUDENT_FIRSTNAME]', $result['payload']['message']);
        $this->assertStringContainsString('[STUDENT_NAME]', $result['payload']['instructions']);
    }

    /**
     * An invalid byte BETWEEN the first and last name must not smuggle the whole name through.
     *
     * This is the position that matters: a non-breaking space that arrived as byte 0xA0, the way a
     * different encoding writes it, is not valid UTF-8 on its own. Repairing
     * the text by DELETING that byte glues "Eva" to "Pérez", and the replacement only matches a name
     * standing on its own, so neither the full name nor either part matches any more and the whole
     * name travels to the AI service intact - with no error, since the replacement did run.
     * Repairing by SUBSTITUTING a replacement character instead keeps the two words apart, and the
     * character it inserts is neither a letter nor a digit, so both parts are still recognised.
     *
     * @return void
     */
    public function test_an_invalid_byte_between_the_names_does_not_smuggle_the_name_through(): void {
        $this->resetAfterTest(true);
        $student = $this->create_student();

        $payload = ['instructions' => "Dirigite a Eva\xA0Pérez con cercanía."];

        $result = payload_anonymizer::anonymize($payload, $student);

        $this->assertStringNotContainsString('Eva', $result['payload']['instructions']);
        $this->assertStringNotContainsString('Pérez', $result['payload']['instructions']);
    }

    /**
     * A damaged stored name must not let the clean name in the text through.
     *
     * The two sides do not have to carry the same bytes. A profile damaged by an external system
     * keeps its stray byte, while the teacher simply typed the name correctly in the instructions,
     * so the repaired name ("Eva" plus a replacement character) no longer matches the "Eva" that is
     * actually in the text. Matching only the repaired form leaves the first name in the request.
     *
     * @return void
     */
    public function test_a_damaged_stored_name_still_matches_the_clean_name_in_the_text(): void {
        $this->resetAfterTest(true);
        $student = $this->create_student();
        $student->firstname = 'Eva' . self::INVALID_UTF8;

        $payload = ['instructions' => 'Dirigite a Eva Pérez con cercanía.'];

        $result = payload_anonymizer::anonymize($payload, $student);

        $this->assertStringNotContainsString('Eva', $result['payload']['instructions']);
        $this->assertStringNotContainsString('Pérez', $result['payload']['instructions']);
    }

    /**
     * What is put back into the generated activity must be storable.
     *
     * The placeholders are restored into the module the service returns, and that module is written
     * to the database, which rejects text that is not valid UTF-8. Before the text was repaired no
     * placeholder was ever inserted for a damaged name, so nothing was restored either; now that one
     * is, the value restored in its place has to be valid.
     *
     * @return void
     */
    public function test_the_values_restored_into_the_generated_activity_are_valid_utf8(): void {
        $this->resetAfterTest(true);
        $student = $this->create_student();
        $student->firstname = 'Eva' . self::INVALID_UTF8;

        $result = payload_anonymizer::anonymize(['instructions' => 'Para Eva Pérez.'], $student);

        foreach ($result['replacements'] as $placeholder => $value) {
            $this->assertTrue(
                mb_check_encoding($value, 'UTF-8'),
                "The value restored for {$placeholder} cannot be written to the database."
            );
        }
    }

    /**
     * Text that is already valid is sent exactly as it was written.
     *
     * Repairing the encoding rewrites the text that leaves the site, so it must happen only when
     * there is something to repair. A teacher's accented paragraph must arrive at the service as
     * typed, minus the names.
     *
     * @return void
     */
    public function test_valid_text_is_not_rewritten(): void {
        $this->resetAfterTest(true);
        $student = $this->create_student();

        // The name must be in the text, or the replacement loop never runs and this proves nothing.
        $payload = ['instructions' => 'Explicá a Eva Pérez la lección con ejemplos cercanos, en español.'];

        $result = payload_anonymizer::anonymize($payload, $student);

        $this->assertSame(
            'Explicá a [STUDENT_NAME] la lección con ejemplos cercanos, en español.',
            $result['payload']['instructions']
        );
    }

    /**
     * A name that itself carries an invalid byte is removed when the text carries the same damage.
     *
     * The pattern is built from the name, so an invalid byte on that side makes the whole pattern
     * invalid and preg_replace() fails exactly the same way. Here both sides carry the same bytes;
     * the case where only one of them does is covered separately.
     *
     * @return void
     */
    public function test_an_invalid_byte_in_the_name_does_not_let_the_name_through(): void {
        $this->resetAfterTest(true);
        // Moodle strips invalid bytes when it WRITES a user, so the byte is put on the object the
        // action is handed, which is where such a value realistically survives: a record read from
        // elsewhere, a field composed in memory, a profile assembled by an authentication plugin.
        $student = $this->create_student();
        $student->firstname = 'Eva' . self::INVALID_UTF8;

        $payload = ['message' => 'Refuerzo para Eva' . self::INVALID_UTF8 . ' sobre fracciones.'];

        $result = payload_anonymizer::anonymize($payload, $student);

        $this->assertStringNotContainsString('Eva', $result['payload']['message']);
        $this->assertStringContainsString('[STUDENT_FIRSTNAME]', $result['payload']['message']);
    }

    /**
     * MDL-UNIT-016: only whole-word name occurrences are anonymised, a prefix of another word is left intact.
     *
     * A name that happens to be the prefix of an unrelated word must be left alone, while the
     * same name standing on its own (including next to punctuation) is replaced.
     */
    public function test_anonymize_replaces_whole_words_only(): void {
        $this->resetAfterTest(true);
        $user = $this->create_student();

        $result = payload_anonymizer::anonymize([
            'instructions' => 'Evaluación para Eva, sobre fracciones. Eva debe repasar.',
        ], $user);

        $this->assertSame(
            'Evaluación para [STUDENT_FIRSTNAME], sobre fracciones. [STUDENT_FIRSTNAME] debe repasar.',
            $result['payload']['instructions']
        );
    }

    /**
     * Names with punctuation or accents, and their adjacency to letters and digits.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function whole_word_boundaries_provider(): array {
        return [
            'apostrophe' => [
                "O'Brien",
                "Ask O'Brien to review; O'Briens is not her.",
                'Ask [STUDENT_FIRSTNAME] to review; O\'Briens is not her.',
            ],
            'hyphen' => [
                'Anne-Marie',
                'Anne-Marie arrives first; Anne-Maries do not.',
                '[STUDENT_FIRSTNAME] arrives first; Anne-Maries do not.',
            ],
            'parentheses' => [
                'Smith (Jr.)',
                'Meet Smith (Jr.) today, not Smith (Jr.)s.',
                'Meet [STUDENT_FIRSTNAME] today, not Smith (Jr.)s.',
            ],
            'accent' => [
                'José',
                'Hola José, los Josés no.',
                'Hola [STUDENT_FIRSTNAME], los Josés no.',
            ],
            'possessive' => [
                'Eva',
                "Eva's notebook; Evaluate Eva.",
                "[STUDENT_FIRSTNAME]'s notebook; Evaluate [STUDENT_FIRSTNAME].",
            ],
            'digits glue like letters' => [
                'Ana',
                'Ana2 logged in; 2Ana too; Ana did.',
                'Ana2 logged in; 2Ana too; [STUDENT_FIRSTNAME] did.',
            ],
        ];
    }

    /**
     * MDL-UNIT-016: name anonymisation respects word boundaries across punctuation, accents and digits.
     *
     * A name is replaced when it stands alone (next to whitespace or punctuation, including an
     * apostrophe) and left alone when glued to letters or digits on either side.
     *
     * @dataProvider whole_word_boundaries_provider
     * @param string $firstname Student first name, possibly containing punctuation or accents.
     * @param string $subject Text mentioning the name standalone and glued.
     * @param string $expected Text after anonymization.
     */
    public function test_anonymize_respects_word_boundaries(string $firstname, string $subject, string $expected): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user([
            'firstname' => $firstname,
            'lastname' => 'Zeta',
        ]);

        $result = payload_anonymizer::anonymize(['instructions' => $subject], $user);

        $this->assertSame($expected, $result['payload']['instructions']);
    }

    /**
     * MDL-UNIT-016: the full name is replaced before its parts, avoiding partial mangling.
     *
     * The full name must be replaced as a unit before its parts, so a mention of the full name
     * yields a single [STUDENT_NAME] token and isolated parts yield their own tokens.
     */
    public function test_anonymize_replaces_full_name_before_parts(): void {
        $this->resetAfterTest(true);
        $user = $this->create_student();

        $result = payload_anonymizer::anonymize([
            'instructions' => 'Ayuda a Eva Pérez; luego Eva y Pérez por separado.',
        ], $user);

        $this->assertSame(
            'Ayuda a [STUDENT_NAME]; luego [STUDENT_FIRSTNAME] y [STUDENT_LASTNAME] por separado.',
            $result['payload']['instructions']
        );
        $this->assertSame([
            '[STUDENT_NAME]' => 'Eva Pérez',
            '[STUDENT_FIRSTNAME]' => 'Eva',
            '[STUDENT_LASTNAME]' => 'Pérez',
        ], $result['replacements']);
    }

    /**
     * MDL-UNIT-016: only the free-text keys are anonymised, every other key travels untouched.
     *
     * Both free-text keys are anonymized; every other key travels untouched.
     */
    public function test_anonymize_handles_message_and_instructions_keys_only(): void {
        $this->resetAfterTest(true);
        $user = $this->create_student();

        $result = payload_anonymizer::anonymize([
            'message' => 'Hola Eva',
            'instructions' => 'Refuerzo para Eva Pérez',
            'lang' => 'es',
            'userid' => 'Eva',
        ], $user);

        $this->assertSame('Hola [STUDENT_FIRSTNAME]', $result['payload']['message']);
        $this->assertSame('Refuerzo para [STUDENT_NAME]', $result['payload']['instructions']);
        $this->assertSame('es', $result['payload']['lang']);
        $this->assertSame('Eva', $result['payload']['userid']);
    }

    /**
     * MDL-UNIT-016: de-anonymisation restores names recursively through nested structures.
     *
     * De-anonymizing the AI result restores the original names recursively through nested arrays.
     */
    public function test_deanonymize_data_round_trip_on_nested_arrays(): void {
        $this->resetAfterTest(true);
        $user = $this->create_student();

        $anonymized = payload_anonymizer::anonymize(['instructions' => 'Plan para Eva Pérez y Eva'], $user);

        $airesult = [
            'resource_type' => 'page',
            'parameters' => [
                'name' => 'Refuerzo de [STUDENT_NAME]',
                'page' => ['text' => '<p>Hola [STUDENT_FIRSTNAME] [STUDENT_LASTNAME]</p>', 'format' => FORMAT_HTML],
                'display' => 5,
            ],
        ];

        $restored = payload_anonymizer::deanonymize_data($airesult, $anonymized['replacements']);

        $this->assertSame('Refuerzo de Eva Pérez', $restored['parameters']['name']);
        $this->assertSame('<p>Hola Eva Pérez</p>', $restored['parameters']['page']['text']);
        $this->assertSame(5, $restored['parameters']['display']);
        $this->assertSame('page', $restored['resource_type']);
    }
}
