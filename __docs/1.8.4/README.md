# User documentation update for 1.8.4

The plugin's `README.md` documents how to build a rule, and every condition and action it
offers. It does not yet document what happens **after** a rule is activated, which is where
1.8.3 and 1.8.4 changed the most: activation became permanent, a sealed rule can be copied
instead of edited, and two of the plugin's own safeguards now refuse a save that would take an
activity away from students.

This folder holds that update, written to be folded into `README.md`. Nothing here contradicts
the current README; every file says where its content belongs.

| File | What it contains | Where it goes in `README.md` |
|---|---|---|
| `activation-and-editing.md` | Activation is permanent, what a sealed rule still allows, and who can delete one | New section after `Adding rules to a course` |
| `duplicating-a-rule.md` | The copy control, what travels to the copy and what does not | New section after `activation-and-editing` |
| `enable-activity-updates.md` | Two activities cannot be shared between actions, and what an action with nothing selected shows | Replaces the end of `### Enable activity` |
| `deleted-activities.md` | What a rule shows and refuses when a target activity is deleted | New section after `Available actions` |
| `screenshots-needed.md` | The images this update needs, with the exact screen and state for each | Capture into `__docs/images/` |

## Why the update exists

Every behaviour described here is user-visible and was verified by the automated suite, but a
teacher cannot discover any of it from the current documentation. Two of them refuse a save,
which is the kind of thing support tickets are made of when it is undocumented:

- A rule cannot be activated while one of its actions could do nothing.
- An activity another action already opens cannot be added to a second action.

## Scope note

The 1.8.4 entries in `CHANGES.md` remain the record of what changed and of what is deliberately
out of scope. This folder is the user-facing half: the same facts, written as instructions rather
than as release notes.
