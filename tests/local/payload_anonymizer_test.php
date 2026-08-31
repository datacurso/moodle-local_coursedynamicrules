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

    /**
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
