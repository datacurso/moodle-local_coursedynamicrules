<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Payload anonymizer for local_coursedynamicrules AI requests.
 *
 * @package     local_coursedynamicrules
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursedynamicrules\local;

/**
 * Handles anonymization/de-anonymization for AI payloads.
 */
class payload_anonymizer {
    /** @var string[] Payload keys carrying free text that may name the student. */
    private const TEXT_KEYS = ['message', 'instructions'];

    /**
     * Build replacement map for student-related user fields.
     *
     * @param \stdClass $user
     * @return array<string, string>
     */
    private static function build_replacements(\stdClass $user): array {
        $replacements = [];

        $studentname = trim(fullname($user));
        if ($studentname !== '') {
            $replacements['[STUDENT_NAME]'] = $studentname;
        }

        if (!empty($user->firstname) && is_string($user->firstname)) {
            $replacements['[STUDENT_FIRSTNAME]'] = $user->firstname;
        }

        if (!empty($user->lastname) && is_string($user->lastname)) {
            $replacements['[STUDENT_LASTNAME]'] = $user->lastname;
        }

        return $replacements;
    }

    /**
     * Anonymize configured payload fields.
     *
     * @param array $payload Original payload.
     * @param \stdClass $user Student user data.
     * @return array{payload: array, replacements: array<string, string>}
     */
    public static function anonymize(array $payload, \stdClass $user): array {
        $replacements = self::build_replacements($user);

        if (!empty($replacements)) {
            // Longest values first, so the full name is replaced as a unit before its parts.
            $ordered = $replacements;
            uasort($ordered, fn(string $a, string $b): int => \core_text::strlen($b) <=> \core_text::strlen($a));

            foreach (self::TEXT_KEYS as $key) {
                if (!isset($payload[$key]) || !is_string($payload[$key])) {
                    continue;
                }
                foreach ($ordered as $placeholder => $value) {
                    $payload[$key] = self::replace_whole_word($value, $placeholder, $payload[$key]);
                }
            }
        }

        return [
            'payload' => $payload,
            'replacements' => $replacements,
        ];
    }

    /**
     * Replace every standalone occurrence of a name with its placeholder.
     *
     * A match must not be glued to another letter or digit on either side (Unicode-aware), so
     * "Eva" is replaced in "para Eva," but left alone inside "Evaluación" or "Eva2". Punctuation
     * (including apostrophes) and whitespace count as boundaries.
     *
     * @param string $needle Original value to hide.
     * @param string $placeholder Placeholder token to insert.
     * @param string $subject Text to process.
     * @return string
     */
    private static function replace_whole_word(string $needle, string $placeholder, string $subject): string {
        $pattern = '/(?<![\pL\pN])' . preg_quote($needle, '/') . '(?![\pL\pN])/u';
        $result = preg_replace($pattern, $placeholder, $subject);

        // preg_replace() yields null on invalid UTF-8: keep the text rather than dropping it.
        return $result ?? $subject;
    }

    /**
     * Restore anonymized placeholders in a text value.
     *
     * @param string $text Text to deanonymize.
     * @param array $replacements Placeholder to original value map.
     * @return string
     */
    public static function deanonymize_text(string $text, array $replacements): string {
        if ($text === '' || empty($replacements)) {
            return $text;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Restore anonymized placeholders recursively in response data.
     *
     * @param mixed $value Value to deanonymize.
     * @param array $replacements Placeholder to original value map.
     * @return mixed
     */
    public static function deanonymize_data($value, array $replacements) {
        if (empty($replacements)) {
            return $value;
        }

        if (is_string($value)) {
            return self::deanonymize_text($value, $replacements);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::deanonymize_data($item, $replacements);
        }

        return $value;
    }
}
