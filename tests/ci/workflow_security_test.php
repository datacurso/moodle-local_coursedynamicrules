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
 * Guards the GitHub Actions workflows shipped with this plugin against script injection.
 *
 * CDR-SEC-003: the release workflow wrote the workflow_dispatch "tag" input straight into a bash
 * step, as "${{ github.event.inputs.tag }}", in a job whose environment held the moodle.org
 * publishing token. GitHub replaces the expression with the raw text before bash runs, so a tag
 * such as 1.8.7$(cmd) ran cmd, with the token one environment variable away. That workflow was
 * removed; these tests keep the pattern, and the token, from coming back.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class workflow_security_test extends \advanced_testcase {
    /**
     * Expression contexts an outside party can set: dispatch and call inputs, release, pull
     * request, issue and comment text, branch names and commit messages.
     */
    private const UNTRUSTED_CONTEXT = '/(?<![\w.])(github\.event\.inputs\.|inputs\.|github\.event\.release\.|github\.head_ref\b'
        . '|github\.event\.pull_request\.|github\.event\.issue\.|github\.event\.comment\.|github\.event\.head_commit\.)/';

    /**
     * No workflow step hands untrusted event data to the shell as code.
     *
     * The safe form passes the value through a step-level env: mapping and reads it as "$VAR",
     * where bash treats it as data. The detector only looks inside run: steps, so that form passes.
     */
    public function test_no_workflow_interpolates_untrusted_input_into_a_shell_step(): void {
        $findings = [];
        foreach (self::workflow_files() as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            foreach (self::find_untrusted_interpolations(file_get_contents($path)) as $lineno) {
                $findings[] = basename($path) . ':' . $lineno . ': ' . trim($lines[$lineno - 1]);
            }
        }

        $this->assertSame(
            [],
            $findings,
            "A run: step interpolates untrusted input with \${{ }}. GitHub pastes the raw text into the "
                . "script before the shell parses it, so the value runs as code. Pass it through env: and "
                . "read it as \"\$VAR\" instead.\n" . implode("\n", $findings)
        );
    }

    /**
     * No workflow references the moodle.org publishing token.
     *
     * Releases reach the plugins directory without it, so any workflow that asks for it again
     * is putting a credential within reach of a job that does not need it.
     */
    public function test_no_workflow_exposes_the_moodle_org_token(): void {
        $offenders = [];
        foreach (self::workflow_files() as $path) {
            if (str_contains(file_get_contents($path), 'secrets.MOODLE_ORG_TOKEN')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, 'These workflows reference secrets.MOODLE_ORG_TOKEN: ' . implode(', ', $offenders));
    }

    /**
     * The detector flags the injection pattern and leaves the safe forms alone.
     *
     * @dataProvider detector_cases_provider
     * @param string $yaml A workflow snippet.
     * @param int[] $expected The 1-based lines the detector must report.
     */
    public function test_detector_finds_exactly_the_injectable_lines(string $yaml, array $expected): void {
        $this->assertSame($expected, self::find_untrusted_interpolations($yaml));
    }

    /**
     * Workflow snippets and the lines the detector must flag in each.
     *
     * @return array
     */
    public static function detector_cases_provider(): array {
        return [
            'dispatch tag pasted into a run block' => [
                "steps:\n"
                    . "  - name: Release\n"
                    . "    run: |\n"
                    . "      if [[ -n \"\${{ github.event.inputs.tag }}\" ]]; then\n"
                    . "        TAGNAME=\"\${{ github.event.inputs.tag }}\"\n"
                    . "      fi\n",
                [4, 5],
            ],
            'input pasted into a single-line run' => [
                "steps:\n"
                    . "  - run: echo \${{ inputs.tag }}\n",
                [2],
            ],
            'pull request title in a folded run block' => [
                "steps:\n"
                    . "  - run: >-\n"
                    . "      echo \"\${{ github.event.pull_request.title }}\"\n",
                [3],
            ],
            'the same input passed as data through env' => [
                "steps:\n"
                    . "  - name: Release\n"
                    . "    env:\n"
                    . "      TAG: \${{ github.event.inputs.tag }}\n"
                    . "    run: |\n"
                    . "      TAGNAME=\"\$TAG\"\n",
                [],
            ],
            'matrix and secrets in with and env' => [
                "steps:\n"
                    . "  - uses: shivammathur/setup-php@v2\n"
                    . "    with:\n"
                    . "      php-version: \${{ matrix.php }}\n"
                    . "  - run: moodle-plugin-ci install\n"
                    . "    env:\n"
                    . "      TOKEN: \${{ secrets.X }}\n",
                [],
            ],
            'the run block ends where the indentation does' => [
                "steps:\n"
                    . "  - run: |\n"
                    . "      echo start\n"
                    . "\n"
                    . "      echo \${{ matrix.php }}\n"
                    . "    with:\n"
                    . "      tag: \${{ inputs.tag }}\n",
                [],
            ],
        ];
    }

    /**
     * The plugin CI runs on its own for every pull request to main and the stable branches.
     *
     * CDR-SEC-007: plugin-ci.yml only declared workflow_dispatch, so it never ran unless someone
     * started it by hand, and changes reached the stable branches without it.
     */
    public function test_plugin_ci_runs_automatically_on_pull_requests(): void {
        $on = self::top_level_block(self::plugin_ci_workflow(), 'on');

        $this->assertNotNull($on, 'plugin-ci.yml has no top-level on: block.');
        $this->assertMatchesRegularExpression('/^\s+pull_request:/m', $on, 'plugin-ci.yml does not run on pull requests.');
        $this->assertMatchesRegularExpression('/^\s+workflow_dispatch:/m', $on, 'plugin-ci.yml lost its manual trigger.');

        $branches = self::pull_request_branches($on);
        $this->assertContains('MOODLE_*_STABLE', $branches, 'The pull_request trigger does not cover the stable branches.');
        $this->assertContains('main', $branches, 'The pull_request trigger does not cover main.');
    }

    /**
     * The plugin CI token can read the repository and nothing more.
     */
    public function test_plugin_ci_token_is_read_only(): void {
        $permissions = self::top_level_block(self::plugin_ci_workflow(), 'permissions');

        $this->assertNotNull(
            $permissions,
            'plugin-ci.yml sets no top-level permissions, so its token gets the repository default.'
        );
        $this->assertMatchesRegularExpression('/^\s+contents:\s*read\s*$/m', $permissions);
        $this->assertDoesNotMatchRegularExpression('/\bwrite\b/', $permissions, 'The plugin CI token must not grant write access.');
    }

    /**
     * Returns the source of plugin-ci.yml, or skips when the package does not ship it.
     *
     * @return string
     */
    private static function plugin_ci_workflow(): string {
        $path = __DIR__ . '/../../.github/workflows/plugin-ci.yml';
        if (!is_file($path)) {
            self::markTestSkipped('This copy of the plugin ships no plugin-ci.yml workflow.');
        }
        return file_get_contents($path);
    }

    /**
     * Returns a top-level YAML key's inline value and indented body, or null when it is missing.
     *
     * @param string $yaml Workflow source.
     * @param string $key Top-level key.
     * @return string|null
     */
    private static function top_level_block(string $yaml, string $key): ?string {
        $pattern = '/^' . preg_quote($key, '/') . ':(.*\R(?:(?:[ \t]+.*|[ \t]*)(?:\R|$))*)/m';
        return preg_match($pattern, $yaml, $match) ? $match[1] : null;
    }

    /**
     * Returns the branch filter of the pull_request trigger, in block or flow style.
     *
     * @param string $on Body of the top-level on: block.
     * @return string[]
     */
    private static function pull_request_branches(string $on): array {
        if (!preg_match('/^([ \t]+)pull_request:.*\R((?:\1[ \t]+.*\R?|[ \t]*\R)*)/m', $on, $trigger)) {
            return [];
        }
        if (!preg_match('/^[ \t]+branches:[ \t]*(\[.*\])?[ \t]*\R?((?:[ \t]+-[ \t]+.*\R?)*)/m', $trigger[2], $filter)) {
            return [];
        }
        $items = !empty($filter[1]) ? explode(',', trim($filter[1], '[] ')) : preg_split('/\R/', trim($filter[2]));
        return array_map(fn(string $item): string => trim(ltrim(trim($item), '- '), "'\" "), $items);
    }

    /**
     * Returns the workflow files to audit, or skips when the package does not ship them.
     *
     * @return string[]
     */
    private static function workflow_files(): array {
        $dir = __DIR__ . '/../../.github/workflows';
        if (!is_dir($dir)) {
            self::markTestSkipped('This copy of the plugin ships no .github/workflows directory.');
        }

        $files = array_merge(glob($dir . '/*.yml') ?: [], glob($dir . '/*.yaml') ?: []);
        sort($files);
        return $files;
    }

    /**
     * Finds the lines of run: steps that interpolate untrusted context with ${{ }}.
     *
     * A line-based guard, not a YAML parser. It understands a single-line "run: ..." and a
     * "run: |" or "run: >" block scalar (with chomping or indentation indicators), which it treats
     * as ending at the first non-blank line indented no deeper than the run: key. It does not see
     * run commands written in flow style ({run: ...}), as quoted multi-line scalars, through YAML
     * anchors, or in composite actions outside .github/workflows; nor an expression split over
     * two lines; nor untrusted data that reaches the shell indirectly, for example through a
     * step output. Expressions in env:, with: or if: are not reported: those are not parsed by
     * a shell.
     *
     * @param string $yaml Workflow source.
     * @return int[] 1-based numbers of the offending lines, in order.
     */
    private static function find_untrusted_interpolations(string $yaml): array {
        $found = [];
        $blockindent = null;

        foreach (preg_split('/\R/', $yaml) as $index => $line) {
            $indent = strlen($line) - strlen(ltrim($line, ' '));

            if ($blockindent !== null) {
                if (trim($line) === '' || $indent > $blockindent) {
                    $shell = $line;
                } else {
                    $blockindent = null;
                }
            }

            if ($blockindent === null) {
                if (!preg_match('/^(\s*(?:-\s+)?)run:\s*(.*)$/', $line, $match)) {
                    continue;
                }
                if (preg_match('/^[|>][-+0-9]*\s*(#.*)?$/', $match[2])) {
                    $blockindent = strlen($match[1]);
                    continue;
                }
                $shell = $match[2];
            }

            preg_match_all('/\$\{\{(.*?)\}\}/', $shell, $expressions);
            foreach ($expressions[1] as $expression) {
                if (preg_match(self::UNTRUSTED_CONTEXT, $expression)) {
                    $found[] = $index + 1;
                    break;
                }
            }
        }

        return $found;
    }
}
