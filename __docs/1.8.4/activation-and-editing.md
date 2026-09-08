<!-- Goes into README.md as a new section, right after "Adding rules to a course". -->

## Activating a rule, and what changes afterwards

Activation is deliberate and permanent. A rule you have never activated is yours to change: its
name, its conditions and its actions can all be edited or removed. From the first activation
onwards, the rule keeps doing what was reviewed, and only its `Active` box can still be changed.

1. Check the `Active` box on the rule and save.
2. A confirmation page explains that activation cannot be undone. Confirm it to activate the rule.

    Cancelling the confirmation keeps every edit you had saved, and leaves the rule inactive.

### What an activated rule still allows

- **Pausing and reactivating**, as often as you need. Unchecking `Active` pauses the rule; the
  list then shows it as `Paused`. Pausing never restarts the rule's schedule.
- **Deleting the whole rule**, which also removes its conditions and actions. Deleting an
  activated rule additionally requires the `local/coursedynamicrules:deletesealedrule`
  capability, held by managers. A teacher can delete only rules that were never activated.
- **Copying it into a new draft**, which is how an activated rule's ideas can still be changed.
  See [Duplicating a rule](#duplicating-a-rule).

### What it refuses

- Editing the rule's name or description.
- Adding, editing or removing its conditions and actions. Their edit controls are replaced by a
  view control, so the components can still be read.

### When activation itself is refused

A rule can only be activated once it can actually do something. The form refuses activation and
reports the rule as incomplete while any of these is true:

- It has no conditions, or no actions.
- One of its `Enable activity` actions has no activities selected. This is how the copy of such
  an action starts, so choose its activities before activating the copy.
- One of its `Enable activity` actions lists only activities that have since been deleted, or
  that are waiting in the course recycle bin.

A rule with a condition pointing at a deleted activity can still be activated. The condition is
shown with a warning in every listing so you can see it, and remove it, before activating.
