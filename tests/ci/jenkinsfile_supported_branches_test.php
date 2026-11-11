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

namespace local_coursedynamicrules\ci;

/**
 * The Jenkins check runs the highest Moodle branch the plugin supports and nothing outside it (CDR-SEC-007).
 *
 * The Jenkins check required by the branch ruleset is the only automatic check before a change
 * reaches a stable branch. Raising `$plugin->supported` without moving Jenkins to the new highest
 * branch would declare a release supported while nothing ever runs the suite on it, and a Jenkins
 * branch outside the range would hide a stale declaration. Both files are read as
 * text, so the test needs nothing but the plugin itself.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class jenkinsfile_supported_branches_test extends \basic_testcase {
    /** @var int[] Moodle releases from 4.5 on; Moodle has no 4.6 to 4.9. */
    private const MOODLE_RELEASES = [405, 500, 501, 502, 503];

    /**
     * The versions declared in version.php's `$plugin->supported`, e.g. [500, 503].
     *
     * Read with a fresh object so the plugin's real file is parsed, not a cached copy.
     *
     * @return int[]
     */
    private function supported_versions(): array {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        $this->assertIsArray($plugin->supported ?? null, 'version.php must declare $plugin->supported');
        return array_map('intval', $plugin->supported);
    }

    /**
     * The Moodle versions the Jenkinsfile runs, from its MOODLE_XYZ_STABLE branch names.
     *
     * @return int[]
     */
    private function jenkins_versions(): array {
        $jenkinsfile = file_get_contents(__DIR__ . '/../../Jenkinsfile.moodleplugin');
        $this->assertNotFalse($jenkinsfile, 'the plugin must ship Jenkinsfile.moodleplugin');
        // Comments are dropped first, so a branch named only in a comment does not count.
        $code = preg_replace('~^\s*//.*$~m', '', $jenkinsfile);
        preg_match_all("~version:\s*'MOODLE_(\d{3})_STABLE'~", $code, $matches);
        return array_map('intval', $matches[1]);
    }

    /**
     * Both ends of `$plugin->supported` are real Moodle releases, in order.
     */
    public function test_the_supported_range_names_moodle_releases(): void {
        $supported = $this->supported_versions();
        $this->assertCount(2, $supported, '$plugin->supported must name the lowest and the highest Moodle release');
        [$lowest, $highest] = $supported;
        $this->assertContains($lowest, self::MOODLE_RELEASES, "\$plugin->supported starts at {$lowest}, not a Moodle release");
        $this->assertContains($highest, self::MOODLE_RELEASES, "\$plugin->supported ends at {$highest}, not a Moodle release");
        $this->assertLessThanOrEqual($highest, $lowest, '$plugin->supported must go from the lowest to the highest release');
    }

    /**
     * Jenkins runs the highest Moodle branch the plugin declares it supports.
     *
     * One branch per Moodle line: the line's CI runs its newest supported release, where the
     * removals and deprecations of every earlier release in the range have already landed.
     */
    public function test_jenkins_runs_the_highest_supported_moodle_branch(): void {
        $jenkins = $this->jenkins_versions();
        $this->assertNotEmpty($jenkins, 'the Jenkinsfile must list at least one MOODLE_XYZ_STABLE branch');

        $highest = max($this->supported_versions());
        $this->assertContains(
            $highest,
            $jenkins,
            "\$plugin->supported goes up to {$highest} but Jenkinsfile.moodleplugin has no MOODLE_{$highest}_STABLE branch"
        );
    }

    /**
     * Every branch Jenkins runs is a real Moodle release, so a typo cannot pass as coverage.
     */
    public function test_every_jenkins_branch_is_a_moodle_release(): void {
        foreach ($this->jenkins_versions() as $version) {
            $this->assertContains($version, self::MOODLE_RELEASES, "MOODLE_{$version}_STABLE is not a Moodle release");
        }
    }

    /**
     * Jenkins runs no branch outside the supported range, which would hide a stale declaration.
     */
    public function test_every_jenkins_branch_is_within_the_supported_range(): void {
        $supported = $this->supported_versions();
        foreach ($this->jenkins_versions() as $version) {
            $this->assertGreaterThanOrEqual(min($supported), $version, "MOODLE_{$version}_STABLE is below \$plugin->supported");
            $this->assertLessThanOrEqual(max($supported), $version, "MOODLE_{$version}_STABLE is above \$plugin->supported");
        }
    }
}
