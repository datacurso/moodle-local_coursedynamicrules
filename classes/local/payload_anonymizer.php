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

    /** @var string Placeholder of the student's email address. */
    private const EMAIL = '[STUDENT_EMAIL]';

    /** @var string Placeholder of the student's username. */
    private const USERNAME = '[STUDENT_USERNAME]';

    /** @var string Placeholder of the student's ID number. */
    private const IDNUMBER = '[STUDENT_IDNUMBER]';

    /** @var string Placeholder of the URL of the course the activity is generated in. */
    private const COURSE_URL = '[COURSE_URL]';

    /**
     * Build the replacement map: what is hidden from the service, and restored in its answer.
     *
     * The student's name is what the activity addresses, and it is restored where the service wrote
     * its placeholder. The email, username and ID number are restored the same way, although the
     * service never saw them and so cannot use them for anything: restoring only puts back what the
     * teacher wrote, wherever the service echoed the placeholder, in an activity that stays on this
     * site and is given to that student alone. It also undoes a false positive - a short ID number
     * that matched an ordinary word of the prompt comes back as the teacher wrote it. The course URL
     * is restored so a link the service writes with its placeholder points to the course again.
     *
     * @param \stdClass $user
     * @param string|null $courseurl URL of the course the activity is generated in, when known.
     * @return array<string, string>
     */
    private static function build_replacements(\stdClass $user, ?string $courseurl): array {
        $replacements = [];

        $studentname = trim(fullname($user));
        if ($studentname !== '') {
            $replacements['[STUDENT_NAME]'] = $studentname;
        }

        $fields = [
            '[STUDENT_FIRSTNAME]' => 'firstname',
            '[STUDENT_LASTNAME]' => 'lastname',
            self::EMAIL => 'email',
            self::USERNAME => 'username',
            self::IDNUMBER => 'idnumber',
        ];
        foreach ($fields as $placeholder => $field) {
            $value = $user->{$field} ?? '';
            if (is_string($value) && trim($value) !== '') {
                $replacements[$placeholder] = trim($value);
            }
        }

        if ($courseurl !== null && $courseurl !== '') {
            $replacements[self::COURSE_URL] = $courseurl;
        }

        return $replacements;
    }

    /**
     * Anonymize configured payload fields.
     *
     * @param array $payload Original payload.
     * @param \stdClass $user Student user data.
     * @param string|null $courseurl URL of the course, masked too because it carries the site address and the course id.
     * @return array{payload: array, replacements: array<string, string>}
     */
    public static function anonymize(array $payload, \stdClass $user, ?string $courseurl = null): array {
        $replacements = self::build_replacements($user, $courseurl);

        // Repaired once, here. The map is also what the caller restores into the activity the
        // service generates, and that activity is written to the database, which refuses text that
        // is not valid UTF-8 - so a damaged stored name must not travel back out of this method.
        $replacements = array_map([self::class, 'valid_utf8'], $replacements);

        if (!empty($replacements)) {
            foreach (self::TEXT_KEYS as $key) {
                if (!isset($payload[$key]) || !is_string($payload[$key])) {
                    continue;
                }
                $payload[$key] = self::replace_whole_words($replacements, self::valid_utf8($payload[$key]));
            }
        }

        return [
            'payload' => $payload,
            'replacements' => $replacements,
        ];
    }

    /**
     * Replace every standalone occurrence of each value with its placeholder, in one pass.
     *
     * A match must not be glued to another letter or digit on either side (Unicode-aware), so
     * "Eva" is replaced in "para Eva," but left alone inside "Evaluación" or "Eva2". Punctuation
     * (including apostrophes) and whitespace count as boundaries.
     *
     * An email address is different, because the characters around it ARE punctuation: the student's
     * address must not be matched inside a longer one ("ana.eva@example.com") or before a longer
     * domain ("eva@example.com.co"), while a full stop ending the sentence still ends it. The email
     * and the username are matched in any letter case, as mail and Moodle logins treat them.
     *
     * One pass, longest value first, so the full name is replaced as a unit before its parts, and
     * so a placeholder already written is never searched again: a username such as "student" would
     * otherwise match inside "[STUDENT_NAME]".
     *
     * Both sides must already be valid UTF-8 (see valid_utf8()).
     *
     * @param array<string, string> $replacements Placeholder => value to hide.
     * @param string $subject Text to process.
     * @return string
     * @throws \moodle_exception When the replacement cannot be performed, since returning the text would name the student.
     */
    private static function replace_whole_words(array $replacements, string $subject): string {
        $needles = [];
        foreach ($replacements as $placeholder => $value) {
            foreach (self::match_variants($value) as $variant) {
                if ($variant !== '') {
                    $needles[] = ['placeholder' => $placeholder, 'value' => $variant];
                }
            }
        }
        if ($needles === []) {
            return $subject;
        }
        usort($needles, fn(array $a, array $b): int => \core_text::strlen($b['value']) <=> \core_text::strlen($a['value']));

        $alternatives = [];
        foreach ($needles as $needle) {
            $alternatives[] = '(' . self::pattern_for($needle['placeholder'], $needle['value']) . ')';
        }
        $placeholders = array_column($needles, 'placeholder');

        $result = preg_replace_callback(
            '/' . implode('|', $alternatives) . '/u',
            static function (array $match) use ($placeholders): string {
                foreach ($placeholders as $index => $placeholder) {
                    if (($match[$index + 1] ?? '') !== '') {
                        return $placeholder;
                    }
                }
                return $match[0];
            },
            $subject
        );

        if ($result === null) {
            // Both sides were repaired before this point, so the engine should have no reason to
            // refuse; if it does anyway, handing back the text it was asked to clean is the one
            // outcome that must not happen. Refuse, and let the caller fail the generation rather
            // than send the student's name.
            throw new \moodle_exception(
                'error_anonymisation_failed',
                'local_coursedynamicrules',
                '',
                null,
                preg_last_error_msg()
            );
        }

        return $result;
    }

    /**
     * The pattern (without delimiters) matching one value as a whole word.
     *
     * @param string $placeholder The placeholder the value is replaced with.
     * @param string $value The value to hide.
     * @return string
     */
    private static function pattern_for(string $placeholder, string $value): string {
        $quoted = preg_quote($value, '/');

        if ($placeholder === self::EMAIL) {
            // Not inside a longer local part, and not before more of an address: a dot counts as a
            // boundary only when no letter or digit follows it.
            return '(?i:(?<![\pL\pN._%+\-])' . $quoted . '(?![\pL\pN_%+\-@]|\.[\pL\pN]))';
        }

        $word = '(?<![\pL\pN])' . $quoted . '(?![\pL\pN])';

        return $placeholder === self::USERNAME ? '(?i:' . $word . ')' : $word;
    }

    /**
     * The forms of a name to look for in the text.
     *
     * The stored name and the text do not have to carry the same bytes. A profile damaged by an
     * external system keeps its stray byte, while the teacher simply typed the name correctly, so
     * the repaired name - which now carries a replacement character the teacher never typed - does
     * not match what is actually written. Both forms are therefore looked for: the repaired one,
     * and the one with the damage taken out, which is what a correctly typed name looks like.
     *
     * The reverse case cannot be reached this way: damage INSIDE the name as written in the text
     * ("Ev<byte>a") leaves no form of the stored name to match, and that occurrence stays. It is
     * declared rather than claimed away.
     *
     * @param string $value A repaired name.
     * @return string[] The forms to search for, longest first.
     */
    private static function match_variants(string $value): array {
        $variants = [$value];

        $stripped = str_replace("\u{FFFD}", '', $value);
        if ($stripped !== $value && $stripped !== '') {
            $variants[] = $stripped;
        }

        return $variants;
    }

    /**
     * Return the text as valid UTF-8, repairing it only when it is not.
     *
     * The replacement below runs a Unicode-aware pattern, which the engine refuses outright when
     * either side carries a byte that is not valid UTF-8 - and such a byte arrives easily, in
     * instructions pasted from a word processor or a profile composed elsewhere. Refusing used to
     * mean the ORIGINAL text was kept, so the payload left the site still naming the student.
     *
     * Invalid bytes are replaced by U+FFFD rather than deleted, and the difference is the whole
     * point: deleting the stray byte in "Eva<byte>Perez" glues the two names into one word that no
     * longer matches a name standing on its own, and the full name then travels intact with nothing
     * reporting a problem. U+FFFD is neither a letter nor a digit, so both parts stay recognisable.
     * Core's fix_utf8() deletes, which is why it is not used here.
     *
     * Text that is already valid is returned untouched: this repairs what leaves the site, so it
     * must not rewrite anything it does not have to.
     *
     * @param string $text Text to check.
     * @return string The same text, or a repaired copy of it.
     * @throws \moodle_exception When the text cannot be made valid, since sending it would name the student.
     */
    private static function valid_utf8(string $text): string {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $repaired = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }

        if (!is_string($repaired) || !mb_check_encoding($repaired, 'UTF-8')) {
            throw new \moodle_exception(
                'error_anonymisation_failed',
                'local_coursedynamicrules',
                '',
                null,
                'the text could not be converted to valid UTF-8'
            );
        }

        return $repaired;
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
