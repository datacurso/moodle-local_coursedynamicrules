# Ticket 589127 closure — Smart Rules notifications

- **Date:** 2026-07-28
- **Component:** `local_coursedynamicrules` (Smart Rules AI)
- **Branch / version at closure:** `release/1.7.0` (`2026072300`). Client site was on `1.6.3`
  (`2026050800`) throughout the ticket.
- **Outcome:** Closed with **no code changes**. Every objection resolves to configuration or
  expected behaviour. Verified point by point against the code, not against the changelog.

> **Note on redaction:** client names, site identifiers, personal names, and email addresses have
> been removed from this public document. The full evidence trail (screenshots, account-level
> validations, client-facing messages) is kept in private support records under ticket 589127.

## Executive summary

The ticket accumulated seven client objections plus two follow-up cases. After verifying each against
the source and the client's own admin UI (rule config + participants list + user filter), **none is a
defect in the plugin on `release/1.7.0`**. The one original defect (wrong name in the message) was
already fixed in `1.6.2`/`1.6.3` and confirmed by the client on 2026-06-18. The remaining live
complaints are enrolment/role configuration and a hard-coded name typed into a message body.

The client-facing closing message is kept in the private support records for the ticket.

## Status by objection

| # | Objection | Verdict | Where it lives |
|---|-----------|---------|----------------|
| 1 | Message showed the teacher's name, not the student's | Fixed | Plugin (`1.6.2`/`1.6.3`) |
| 2 | Teacher receives "course not completed" reminder | Expected behaviour | Course enrolment/role |
| 3 | Certificate emails sent in two different versions | Configuration | Certificate module (per course) |
| 4 | Reminder emails sent in two versions | Fixed (rules) / config (other module) | Plugin + Sessions module |
| 5 | Welcome message configuration | Resolved | Core enrolment method |
| 6 | English "Password reset request" subject | Configuration | Core language string |
| 7 | Forum notifications (digest vs per-message) | Configuration | Core `maildigest` |
| + | Wrong (hard-coded) first name in a reminder | Configuration | Hard-coded name in message body |
| + | In-course alerts (login/incomplete/ending reminders) | Out of scope | Different component |

## Evidence for the points that stayed open longest

### #1 — Wrong name in the message (FIXED)

Placeholders are replaced per matched user in
`classes/action/sendnotification/sendnotification_action.php:319-325` (`replace_placeholders`), and the
event observers pass the student via `relateduserid`
(`classes/observer/user_graded.php:51`, `classes/observer/course_module_completion_updated.php:61`).
Client confirmed resolved on 2026-06-18.

### #2 — Teacher receiving the reminder (EXPECTED BEHAVIOUR)

Admin validation (Participants list of the affected course): the reported teacher's account is
enrolled with role **Student**, active. The rule condition is "users who have **not completed** the
Customcert activity" → notify. The 2026-06-23 screenshot shows the message greeting the user by their
own name with subject "Recordatorio…" (not "Observación:"), i.e. the account matched as a **primary**
recipient — consistent with its Student role being in `primaryroleids`
(`sendnotification_action.php:71-82`). The client's premise ("teacher without student role") is
incorrect. No plugin defect. If that account must not be notified, that is managed via its
enrolment/role.

### + — Hard-coded first name in a reminder (CONFIGURATION)

Admin validation (Users → filter by email): the reporting mailbox maps to **exactly one** account, a
test account whose first name does **not** match the name shown in the reminder.
`replace_placeholders` can only inject the recipient's own `firstname`; it cannot produce a name that
belongs to a different person. Therefore the name in the reminder is **literal text typed into the
message body** instead of the `{$a->firstname}` marker. Configuration error, not code.

## Operator actions required before/at closure (no code)

1. **Fix the hard-coded name.** Actions cannot be edited (see below); the fix is to **delete** the
   "Enviar notificación" action of the affected rule and **recreate** it, re-entering subject, roles,
   and a body that uses the `{$a->firstname}` marker.
2. **Remove duplicate rules.** The affected course had two near-identical rules on the same Customcert
   activity, both `Inactiva`. If both were ever active, that alone produces two emails. Keep one.
3. **Unify the certificate email template** for the affected course to match the personalised
   template used by the other courses (certificate module config, not this plugin).

## Constraint discovered during closure: components are add + delete only

Rule **actions** and **conditions** can only be **created or deleted**, never edited:

- `actions.php:62-81` — existing actions expose only a delete URL; the edit form
  (`actions.php:94-114`) only runs for `?type=` (add). `save_action()` is insert-only
  (`sendnotification_action.php:216`).
- `conditions.php` mirrors this (only `deletecondition.php`; `save_condition()` insert-only, e.g.
  `complete_activity_condition.php:136`).
- No `editaction.php` / `editcondition.php` exists. `editrule.php` edits only the rule name.

Consequence for support: on `1.6.3` there is **no UI way to inspect** an action's stored roles or
message body (the list description shows only "…a los usuarios"; the role + body preview in the
description exists only from `c6d443e`/`1.7.0`). Diagnosing this ticket relied on code logic plus the
admin user/participant screens. The remediation for any wrong action content is delete + recreate.

This gap is the motivation for the follow-up enhancement — see
[`enhancement-editable-components.md`](enhancement-editable-components.md).
