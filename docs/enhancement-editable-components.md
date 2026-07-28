# Enhancement head-start — editable conditions and actions

- **Date:** 2026-07-28
- **Component:** `local_coursedynamicrules` (Smart Rules AI)
- **Status:** Backlog. Pre-spec notes to seed a future SDD session. **Not implemented.**
- **Origin:** Ticket 589127 — a hard-coded name in a notification body could only be fixed by
  deleting and recreating the whole action, because components are add/delete only.
  See [`ticket-589127-closure.md`](ticket-589127-closure.md).

## Problem

Rule **actions** and **conditions** cannot be edited. To change a single field (a message body, a role,
a threshold, an activity) the operator must delete the component and recreate it from scratch,
re-entering every field. There is also **no way to view** a component's stored parameters in the UI
(the list only renders a description string), which makes support diagnosis blind on released versions.

## Current architecture (what exists today)

- **List + add pages:** `actions.php`, `conditions.php`.
  - Existing rows get only a delete URL (`actions.php:68-79`, `conditions.php:68-72`).
  - The mform is built and saved only when `?type=<type>` is present → **add** flow
    (`actions.php:94-114`, `conditions.php` equivalent).
- **Persistence is insert-only:**
  - `sendnotification_action.php:216` → `$DB->insert_record('..._action', ...)`.
  - `complete_activity_condition.php:136`, `no_course_access_condition.php:178`, etc.
    → `$DB->insert_record('..._condition', ...)`.
- **Delete endpoints:** `deleteaction.php`, `deletecondition.php` (already ownership-guarded via
  `classes/helper/ownership.php`, per S6 / SATG-SEC-001).
- **Reusable base already supports loading an existing record:**
  - `core/action.php` `set_data()` reads `$record->id` and `json_decode($record->params)`, and
    `get_id()` returns it (`core/action.php:129-145`). `core/condition.php` mirrors this.
  - So loading a persisted component into an instance is already possible; what is missing is the
    **edit endpoint**, **form preload**, and **update-on-save**.

## What needs to change

1. **Edit entry points.** Either new `editaction.php` / `editcondition.php`, or an `?edit=<id>` branch
   inside `actions.php` / `conditions.php`. Prefer reusing the existing pages to keep one ownership
   guard path.
2. **Persistence: insert → upsert.** `save_action()` / `save_condition()` must `update_record` when the
   instance already has an id (`get_id()`), else `insert_record`. Each action/condition type has its own
   `save_*` — every one must be touched.
3. **Form preload.** Each `*_form` must set its defaults from the stored `params` when editing
   (mform `set_data()` / `->set_data($this->params)` mapping). This is the bulk of the per-type work
   because param shapes differ.
4. **Row UI.** Add an edit control next to delete in the templates
   (`templates/conditions.mustache` is reused for both actions and conditions).

## Affected components (per-type work is the cost driver)

| Kind | Types |
|------|-------|
| Actions | `sendnotification`, `createaiactivity`, `enableactivity` |
| Conditions | `complete_activity`, `course_inactivity`, `grade_in_activity`, `no_complete_activity`, `no_course_access`, `passgrade` |

Each needs: `save_*` upsert branch + form default preload + a round-trip test (create → edit → persist).

## Security / correctness constraints (do NOT regress)

- **Ownership (S6 / SATG-SEC-001).** The edit endpoint is a new write path. It MUST load the target
  through `ownership::rule_belongs_to_course()` (and, for the component, verify the component belongs to
  the rule) before showing the form or saving — exactly as the delete endpoints already do. This is the
  same class of bug as the cross-course IDOR that was closed in 1.7.0.
- **Stored XSS (SATG-SEC-002, still OPEN).** `createaiactivity.message` and the notification body are
  free text. An edit form re-submits these; do not widen the sink. Reuse the same
  formatting/escaping applied on the add path and keep the pending XSS remediation in mind — editing
  must not become a second unescaped write path.
- **Input validation (S1).** The condition input guards added in S1 (period/interval/cmid validation)
  live in the forms' `validation()`; editing must run through the same validation, not bypass it.
- **Idempotency.** Editing must not duplicate rows (that is the whole point) — assert a single row
  survives an edit in tests.

## Suggested task breakdown (for the SDD session)

1. **Spec:** requirement "operators can edit an existing condition/action without losing sibling
   components", with scenarios per type (happy path, ownership violation, invalid input rejected).
2. **Design:** decide `?edit=<id>` in existing pages vs. new endpoints; define the upsert contract on
   the base `save_*`; define the form-preload contract.
3. **Apply (TDD, one batch per kind):**
   - a. Base upsert plumbing + ownership guard on the edit path.
   - b. Actions: `sendnotification`, then `createaiactivity`, then `enableactivity`.
   - c. Conditions: the six types.
   - d. Row UI edit control + Mustache/behat.
4. **Verify:** ownership (cross-course attempt rejected), no-duplication, validation reuse, XSS sink not
   widened.

## Open questions

- Reuse `actions.php`/`conditions.php` with `?edit=` (fewer guard paths) or dedicated endpoints
  (cleaner separation)? Recommendation: reuse.
- Should editing a component reset the rule's `nexttimeperiod`/throttle state? Likely yes for conditions
  that change matching semantics — confirm during design.
- Behat coverage: add an edit scenario to the existing notification feature, or a new feature file?
