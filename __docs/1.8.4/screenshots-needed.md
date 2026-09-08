# Screenshots this update needs

The update references five images that do not exist yet. Each one needs a specific screen in a
specific state, so the state to build is written next to it. Save them into `__docs/images/`
with the file names below, matching the existing naming pattern.

| File name | Screen | State to build first |
|---|---|---|
| `local_cdr_duplicate-rule.png` | Rules list, one row | A rule with at least one condition and one action, so the copy control is offered |
| `local_cdr_duplicated-rule.png` | Rules list, two rows | Right after duplicating: the original and its `(copy)` with the `Inactive` badge |
| `local_cdr_enable-activity-alreadygated.png` | `Enable activity` action form, after a refused save | An activity already opened by another action, selected in a second action |
| `local_cdr_component-target-missing.png` | Conditions page of a never-activated rule | An `Activity completed` condition whose activity was deleted from the course |
| `local_cdr_activation-confirmation.png` | Activation confirmation page | A complete rule, `Active` checked and saved |

## Two that would help but are optional

| File name | Screen | State to build first |
|---|---|---|
| `local_cdr_rule-badges.png` | Rules list | Four rules, one of each badge: `Active`, `Paused`, `Executed`, `Inactive` |
| `local_cdr_enable-activity-none-chosen.png` | Rules list or actions page | The copy of a rule whose `Enable activity` action has nothing selected |

Capture them at the same width as the existing images so the README reads consistently, and crop
to the region that carries the message rather than the whole browser window.
