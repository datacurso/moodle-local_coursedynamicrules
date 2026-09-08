<!-- Goes into README.md as a new section, after "Available actions". -->

## When a target activity is deleted

Conditions and actions store the activity they were pointed at. Deleting that activity from the
course does not delete the rule, so the plugin makes the situation visible instead of letting the
component disappear.

### A condition whose activity was deleted

The condition is still listed, and describes itself with a warning:

> The selected activity is no longer available in this course, so this component can never take
> effect.

![Component whose activity was deleted](__docs/images/local_cdr_component-target-missing.png)

- The warning appears in the conditions page, in the rules list, and on the delete confirmation
  page.
- On a rule that was never activated, the condition keeps its delete control, so you can remove
  it. On an activated rule, components can no longer be removed one by one; deleting the whole
  rule is the only exit.
- Editing such a condition opens its form with no activity selected, and saving without choosing
  one is refused. The rule is never silently re-pointed at another activity.
- An activity waiting in the course recycle bin counts as deleted for these descriptions.

### An action whose activities were deleted

An `Enable activity` action omits deleted activities from its description. When every activity it
listed is gone, it shows the same warning as a condition, and the rule can no longer be
activated until the action is pointed at an activity that exists.

### What is not flagged

An action that lists several activities of which only some were deleted reads as if it had only
ever listed the surviving ones. The `Create AI reinforcement activity` action drops its placement
reference silently when the anchor activity was deleted, and places the new activity at the end
of the section instead.
