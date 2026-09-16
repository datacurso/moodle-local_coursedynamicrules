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
 * The release notes must not tell a site the opposite of what the code does.
 *
 * This exists because it happened. The release that added the export and the erasure also shipped,
 * forty-seven lines lower in the SAME section, a "Known limitations" entry stating that the provider
 * "declares data but does not yet export or erase it" and that the registry "renders none of its
 * declared fields". Both sentences had been true the week before. Nobody removed them, and no test
 * could have noticed, because nothing compared the document against the code.
 *
 * It is deliberately NOT a test that the notes contain some blessed wording - that would pin prose
 * and go red on every honest rewrite. It compares the notes against a fact core computes at runtime,
 * in the direction that can do harm: if core counts this component compliant, the notes for the
 * release being shipped must not tell the reader it is not. A site administrator or an auditor reads
 * that file to decide whether a finding is closed; a release that answers that question twice, in
 * opposite directions, is worse than one that stays silent.
 *
 * The phrases below are matched case-insensitively and are the ones that assert non-compliance
 * outright. Prose that DESCRIBES the past ("was not compliant until this release") is written in a
 * tense these needles do not match, on purpose: recording history is not the defect.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class changelog_agreement_test extends \advanced_testcase {
    /** @var string The component under test. */
    private const COMPONENT = 'local_coursedynamicrules';

    /**
     * Claims that assert, in the present tense, that this component does not answer privacy requests.
     *
     * @var string[]
     */
    private const DENIALS = [
        'does not yet export or erase',
        'implements the metadata contract only',
        'lists it as non-compliant',
        'renders none of its declared fields',
        'runs none of the provider',
        'with no ownership marker at all',
    ];

    /**
     * The release notes for the version being shipped, as one string.
     *
     * Read from this checkout rather than from $CFG->dirroot, so the test judges the branch it runs
     * on and not whatever happens to be mounted at the plugin's frankenstyle path.
     *
     * @return string The section of CHANGES.md for the release in version.php.
     */
    private function notes_for_this_release(): string {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        $release = (string) $plugin->release;

        $changes = file_get_contents(__DIR__ . '/../../CHANGES.md');
        $this->assertNotFalse($changes, 'CHANGES.md must be readable.');

        $heading = '## ' . $release;
        $start = strpos($changes, $heading);
        $this->assertNotFalse($start, "CHANGES.md carries no section for the release in version.php ({$release}).");

        // Up to the next top-level release heading, or the end of the file for the newest release.
        $next = strpos($changes, "\n## ", $start + strlen($heading));
        $section = $next === false ? substr($changes, $start) : substr($changes, $start, $next - $start);

        // A release section is made of "## Added" / "## Fixed" / "## Known limitations" subsections,
        // which are top-level headings too, so the search above stops at the first one. Walk forward
        // over every subsection until the next heading that names a release.
        while ($next !== false) {
            $after = substr($changes, $next + 1);
            if (preg_match('/^## \d+\.\d+/', $after) === 1) {
                break;
            }
            $start = $next + 1;
            $next = strpos($changes, "\n## ", $start);
            $section .= $next === false ? substr($changes, $start) : substr($changes, $start, $next - $start);
        }

        return $section;
    }

    /**
     * A release whose component core counts compliant must not say in its own notes that it is not.
     *
     * @return void
     */
    public function test_the_release_notes_do_not_deny_what_the_code_does(): void {
        $this->resetAfterTest(true);

        if (!(new \core_privacy\manager())->component_is_compliant(self::COMPONENT)) {
            // Nothing to contradict: the notes are free to say the component does not export or
            // erase, because it does not.
            $this->markTestSkipped('The component is not compliant, so a note saying so is accurate.');
        }

        $section = strtolower($this->notes_for_this_release());
        $found = [];
        foreach (self::DENIALS as $denial) {
            if (strpos($section, $denial) !== false) {
                $found[] = $denial;
            }
        }

        $this->assertSame(
            [],
            $found,
            'The release notes for the version being shipped state that this component does not answer '
                . 'privacy requests, while core counts it compliant and its provider exports and erases. '
                . 'One of the two is wrong, and the reader has no way to tell which.'
        );
    }

    /**
     * Sanity: the section really was found and really carries the release's own content.
     *
     * Without this, a typo in the heading search would make the test above pass over an empty string
     * for ever - green, and blind.
     *
     * @return void
     */
    public function test_the_release_section_is_really_read(): void {
        $this->resetAfterTest(true);

        $section = $this->notes_for_this_release();

        $this->assertStringContainsString('## Fixed', $section, 'The release section must carry its Fixed list.');
        $this->assertGreaterThan(
            2000,
            strlen($section),
            'The release section came back too short to be the real one; the heading walk is broken.'
        );
    }
}
