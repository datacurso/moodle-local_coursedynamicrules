# CI & PR preflight guide

- **Date:** 2026-07-29
- **Component:** `local_coursedynamicrules` (Smart Rules AI)
- **Purpose:** Pass `moodle-plugin-ci` on the first (or second) try when opening a PR. Every item
  below was a real failure hit while landing the 1.7.0 release PR; most were latent debt that had
  never run through CI.

## Why this exists

Code that reached a branch without ever going through a PR has **not** run `moodle-plugin-ci`.
The 1.7.0 release was assembled from many sub-branches merged into `release/1.7.0` with no PR, so
the entire release ran CI for the first time only when the release PR opened — and surfaced ~5
distinct failures across phpcs, behat and grunt. **Assume un-CI'd code carries style / deprecation /
build debt and budget for it.**

## What CI runs

`.github/workflows/plugin-ci.yml` → `moodle-plugin-ci ^4`, PHP 8.3, `MOODLE_405_STABLE`, on both
`mariadb` and `pgsql` (the two required checks). Steps: `phplint`, `phpmd` (non-failing), **`phpcs`**,
`phpdoc`, `validate`, `savepoints`, `mustache`, **`grunt`**, **`phpunit`**, **`behat`**.

Diagnose failures from the source, never by guessing:

```bash
gh pr checks <pr>
gh run view --job=<jobid> --log-failed
# extract ALL errors with awk between step markers — do NOT head-truncate (a 5th phpcs file was
# missed once by truncating the log):
gh run view --job=<jobid> --log | awk '/RUN  Moodle CodeSniffer/{f=1} f{print} /Run moodle-plugin-ci phpdoc/{exit}'
```

## Failure catalogue and fixes

### phpcs (Moodle Code Checker) — the biggest source

- **"No one-line description found in phpdocs for docblock of function"** — every function docblock
  (including test methods and anonymous-class stubs) needs a one-line summary line before the
  `@param`/`@return` tags.
- **Multi-line function call formatting** — the opening `(` must be the **last** content on its line,
  **one argument per line**, continuation indented +4 (12 spaces for a statement at 8), and the
  closing `)` **alone** on its own line. Fix by collapsing to a single line when it fits in ≤132
  chars, otherwise use the canonical multi-line form. Copy the exact shape from a file that already
  passes (e.g. `tests/action/createaiactivity/createaiactivity_action_test.php`).

### behat (visits real pages, so it catches runtime issues PHPUnit cannot)

- **Undefined property `$config->confirmdelete{action,condition,rule}`** in the `delete*.php` pages:
  the confirmation token is read before it is set on the first visit → PHP 8 warning → behat fails on
  any warning/`debugging()`. Fix: `md5($config->confirmdeleteaction ?? '')`.
- **Deprecated `single_button` boolean** — passing `false` as the 4th arg (`$primary`) is deprecated
  in 4.5 and emits `debugging()`. Use `single_button::BUTTON_SECONDARY`.
- **Behat generator encoding** — store payloads decoded so escaping is exercised once, not twice:
  `html_entity_decode($row['field'], ENT_QUOTES | ENT_HTML5)` in the generator step.

### grunt (stale AMD build)

`amd/build/*.min.js` is stale whenever `amd/src/*.js` changed without a rebuild. Regenerate it (see
"Local tooling" — it must be built in a valid component path, not a worktree).

## Local tooling: what can and cannot be verified on this machine

| Check | Locally? | Why |
|-------|----------|-----|
| `php -l` (syntax) | ✅ | host PHP works; also check lines ≤132 |
| **grunt** (AMD build) | ✅ | host has nvm node 22 (Moodle 4.5 needs `>=22.11 <23`; default node 25 is rejected). See below. |
| **phpcs** | ❌ CI-only | host PHP lacks `ext-simplexml`/`ext-xmlwriter`; no passwordless sudo to add `php-xml`. |
| **behat** | ❌ CI-only | no browser/Selenium locally; Docker mounts the primary tree, not the worktree. *(Goal: make operative later.)* |

### Rebuilding AMD locally (the one build step that IS reproducible)

Moodle's grunt only recognises a **real component path** — it silently builds nothing for a worktree
path such as `local/coursedynamicrules-wt/<branch>`. So build in the primary checkout and copy the
output into the worktree:

```bash
export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use 22.23.1
cd <moodle>/local/coursedynamicrules            # the PRIMARY path, not the worktree
<moodle>/node_modules/.bin/grunt amd             # >2 min; run in background
cp amd/build/<mod>.min.js amd/build/<mod>.min.js.map <worktree>/amd/build/
git checkout -- amd/build/                        # restore the primary tree afterwards
```

> Note: the standalone IDE linter reports Moodle core symbols (`MUST_EXIST`, `single_button`,
> `get_string`, `required_param`, …) as undefined. Those are **false positives** — ignore them.

## Preflight checklist before opening a PR

1. If any `amd/src/*.js` changed since its build, rebuild AMD (primary path + nvm node 22) and commit
   the regenerated `amd/build/*`.
2. Eyeball new/changed PHP for one-line docblocks and multi-line-call formatting.
3. Grep delete/confirm pages for `$config->…` reads before they are set, and for the deprecated
   `single_button` boolean 4th argument.
4. Accept that phpcs and behat are verified only in CI — push, then read the failed log and fix
   every error in one pass (extract with `awk`, not `head`).
