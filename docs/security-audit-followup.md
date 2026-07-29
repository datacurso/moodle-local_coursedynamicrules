# Security audit follow-up — MindFree report (1.6.3 → 1.7.1)

- **Date:** 2026-07-29 (updates the 2026-07-27 follow-up written against pre-merge `release/1.7.0`)
- **Component:** `local_coursedynamicrules` (Smart Rules AI)
- **Scope:** MindFree security report (audited v1.6.3 / `2026050800`) verified against `main` at
  1.7.1 (`c064c3c`). Reviewed file by file, not based on the changelog.

## Executive summary

Both **high findings** that drove the `NO APROBADO` verdict are now **closed**: the cross-course
IDOR and the stored XSS shipped in 1.7.0 (PR #8); the privacy declaration and audit events shipped
in 1.7.1 (PR #9). The plugin now meets the auditor's production criterion. Remaining items are two
lows: scheduled-task cadence (partially addressed via observability) and `debugging()` inside a
`\Throwable` catch (unchanged).

## Status by finding

| ID | Finding | Severity | Status | Shipped in |
|----|---------|----------|--------|------------|
| SATG-SEC-001 | Cross-course IDOR | High | **Closed** | 1.7.0 |
| SATG-SEC-002 | Stored XSS (`createaiactivity.message`) | High | **Closed** | 1.7.0 |
| SATG-PRIV-001 | Privacy API | Medium | **Closed** | 1.7.1 |
| SATG-SEC-003 | Scheduled tasks every minute | Low | Partial (observability added, cadence unchanged) | 1.7.1 |
| A09 | No audit events | Low | **Closed** | 1.7.1 |
| A10 | `debugging()` with `Throwable` | Low | Unchanged | — |

## Closed

### SATG-SEC-001 — Cross-course IDOR (1.7.0)

New helper `classes/helper/ownership.php` enforces course ownership (loads with `courseid` and a `JOIN`
against `rule.courseid`). Wiring verified across all 6 pages:

- `editrule.php:50`
- `conditions.php:53`
- `actions.php:54`
- `deleterule.php:55`
- `deletecondition.php:58`
- `deleteaction.php:57`

Additionally, the submitted rule id is re-validated against the course before an update, so a tampered
hidden id cannot overwrite or move another course's rule (commit `040ad3b`). Documented in `CHANGES.md`
(1.7.0, Security section).

### SATG-SEC-002 — Stored XSS (1.7.0)

- **Root cause:** `rules.php` concatenated `get_description()` into `<p>...</p>` and fed it to an
  `html_table_cell` with no escaping, while the prompt (`createaiactivity.message`, `PARAM_RAW_TRIMMED`)
  reached `get_description()` unescaped (`shorten_text()` and `get_string($a)` do not escape).
- **Fix (escape at every output boundary):** `classes/helper/component_renderer.php` centralises the
  escape. `descriptions_html()` (used by `rules.php`) wraps each description in a paragraph escaped with
  `s()`; `escaped_description()` returns a single escaped description and is used by the delete
  confirmation pages. The prompt is **not** altered at input, so the AI still receives it verbatim
  (escaping is display-only). The Mustache views already escaped via `{{description}}`.
- **Boundaries covered:**
  - `rules.php` (rules list) — via `descriptions_html()`.
  - `deleteaction.php` and `deletecondition.php` — the success notification
    (`notification_base.mustache` renders `{{{ message }}}` raw) and the `confirm()` dialog
    (message built with literal `<br />`) both echoed `get_description()` raw; now escaped via
    `escaped_description()`.
- **Tests (PHPUnit):** `tests/helper/component_renderer_test.php` — a RED test first reproduced the raw
  `<script>` payload at the rules-list boundary; after the fix it asserts escaped output, plus
  triangulation (plain text preserved, empty header/description skipped), a display-only invariant
  (the action's `get_description()` still returns the raw prompt while the rendered boundary escapes
  it), and `escaped_description()` escaping.
- **Tests (Behat):** `tests/behat/stored_xss_action_descriptions.feature` visits the delete action page
  for a malicious prompt and asserts it renders escaped. Green in CI on the merged PRs.
- **Verified in `main`:** `classes/helper/component_renderer.php` present and wired; documented in
  `CHANGES.md` (1.7.0, Security section).

**Note:** the prompt's `PARAM` type was deliberately left as `PARAM_RAW_TRIMMED` — it is sent to the AI
and `PARAM_TEXT` would strip legitimate characters. The defense is at the output boundary, by design.

### SATG-PRIV-001 — Privacy (1.7.1)

`classes/privacy/provider.php` was upgraded from a `null_provider` to a
`\core_privacy\local\metadata\provider` that declares the external transfer to the Datacurso AI
service via `add_external_location_link()` (`userid`, `courseid`, `courseurl`, `prompt`), with the
corresponding `privacy:metadata:datacurso_ai:*` language strings.

**Notifications:** delivery goes through Moodle's core messaging API (`message_send()` with
`\core\message\message` in `sendnotification_action.php`), so declaring any onward external transfer
(e.g. a Twilio / Message Hub output) is the responsibility of the installed message processor plugin,
not this plugin. The `sanitize_html_message_twilio()` helper only formats the small-message text.

The plugin's own tables store configuration only (course ids, module ids, role ids, prompts), so no
data store declaration is required. The previous `LEGAL-REVIEW-REQUIRED` flag is considered resolved
by the explicit external-location declaration.

### A09 — Audit events (1.7.1)

`classes/event/` now defines seven events covering the management lifecycle: `rule_created`,
`rule_updated`, `rule_deleted`, `condition_created`, `condition_deleted`, `action_created`,
`action_deleted`. These operations now appear in Moodle logs and reports. Documented in `CHANGES.md`
(1.7.1, Added).

## Partial

### SATG-SEC-003 — Scheduled tasks

`db/tasks.php` still uses `minute='*'` for `no_complete_activity_task` and `no_course_access_task`
(cadence deliberately unchanged — see `CHANGES.md` 1.7.1). What did change:

- S3 improved user selection (dedup + active-only), lowering per-run cost (1.7.0).
- 1.7.1 added observability: both tasks report per-run counts and duration via `mtrace`, and warn when
  a course exceeds a configurable batch threshold (`taskbatchsize`).

**Pending (low):** revisiting the cadence itself (`minute='*'`) and hard batch limits, now measurable
with the new per-run metrics.

## Open

### A10 — `debugging()` with `Throwable`

Unchanged: `classes/action/createaiactivity/createaiactivity_action.php:123` still catches `\Throwable`
and reports through `debugging()` (`DEBUG_DEVELOPER`). Low severity; a structured log or event would be
the natural follow-up now that A09's event infrastructure exists.

## Verified and clean

- Mustache views escape by default (`conditions.mustache` → `{{description}}`); `conditions.php` and
  `actions.php` are safe.
- Numeric `PARAM_RAW` fields (`periodvalue`, intervals) are mitigated by strict S1 validation
  (`ctype_digit`, `is_valid_*_intervals`) in the form and again in `evaluate()`.
- `sendnotification.messagesubject` = `PARAM_TEXT` (tags stripped); `messagebody` is neutralized by
  `html_to_text()` in the view.

## Proposed next steps

Both production blockers are closed; these are low-priority follow-ups:

1. **SATG-SEC-003** — use the new per-run metrics to decide a saner cadence than `minute='*'` and a
   hard batch limit for the two per-minute tasks.
2. **A10** — replace `debugging()` in the `\Throwable` catch with a structured log/event on the A09
   infrastructure.
