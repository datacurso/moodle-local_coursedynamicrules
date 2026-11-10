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
 * The Jenkins check runs on every Moodle branch the plugin declares it supports (CDR-SEC-007).
 *
 * The Jenkins check required by the branch ruleset is the only automatic check before a change
 * reaches a stable branch. A version added to `$plugin->supported` without its Jenkins branch
 * would be declared supported while nothing ever runs the suite on it. Both files are read as
 * text, so the test needs nothing but the plugin itself.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class jenkinsfile_supported_branches_test extends \basic_testcase {
    /**
     * The versions declared in version.php's `$plugin->supported`, e.g. [405, 503].
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
     * Every version declared in `$plugin->supported` has its MOODLE_XYZ_STABLE branch in Jenkins.
     */
    public function test_every_supported_version_has_a_jenkins_branch(): void {
        $jenkins = $this->jenkins_versions();
        $this->assertNotEmpty($jenkins, 'the Jenkinsfile must list at least one MOODLE_XYZ_STABLE branch');

        foreach ($this->supported_versions() as $version) {
            $this->assertContains(
                $version,
                $jenkins,
                "\$plugin->supported declares {$version} but Jenkinsfile.moodleplugin has no MOODLE_{$version}_STABLE branch"
            );
        }
    }

    /**
     * Every Moodle release inside the supported range has its branch in Jenkins, not only the two ends.
     *
     * `$plugin->supported` is a range: [405, 503] also covers 5.0, 5.1 and 5.2.
     */
    public function test_every_moodle_release_in_the_supported_range_has_a_jenkins_branch(): void {
        // Moodle releases from 4.5 on; Moodle has no 4.6 to 4.9.
        $releases = [405, 500, 501, 502, 503];
        $supported = $this->supported_versions();
        $jenkins = $this->jenkins_versions();

        foreach ($releases as $version) {
            if ($version < min($supported) || $version > max($supported)) {
                continue;
            }
            $this->assertContains(
                $version,
                $jenkins,
                "Moodle {$version} is inside \$plugin->supported but Jenkinsfile.moodleplugin has no MOODLE_{$version}_STABLE branch"
            );
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
