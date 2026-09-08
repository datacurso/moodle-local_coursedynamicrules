<!-- Goes into README.md as a new section, right after "Activating a rule, and what changes
afterwards". -->

## Duplicating a rule

Because an activated rule can no longer be edited, the way to change its ideas is to copy it,
edit the copy, and activate that. The copy is a draft: inactive, never activated, and fully
editable.

1. On the rules list, click the copy control on the rule's row.

    ![Duplicate rule](__docs/images/local_cdr_duplicate-rule.png)

2. The copy appears in the list named `<original name> (copy)`, with the `Inactive` badge. A
   second copy of the same rule is named `(copy 2)`, and so on.

    ![Duplicated rule in the list](__docs/images/local_cdr_duplicated-rule.png)

3. Edit the copy as you would any draft, then activate it through the confirmation flow.

### What the copy receives

- The rule's name, with the copy suffix, and its description.
- Every condition, with its configuration unchanged.
- Every action, with its configuration unchanged, except as described below.

### What the copy does not receive

- **Its own activation history.** The copy is inactive and has never been activated, whatever
  the original's state was, so it is editable and its badge reads `Inactive`.
- **The original's schedule.** A `Course inactivity` condition using `From rule activation`
  measures from the copy's own first activation. A `No course access` condition starts its
  waiting period fresh, so the copy is not held back by however much of the original's period
  was left.
- **The activities of an `Enable activity` action.** That action arrives with nothing selected,
  and reads `Enable activities: none chosen yet`. Choose its activities before activating the
  copy. This is deliberate: see
  [Two actions cannot open the same activity](#two-actions-cannot-open-the-same-activity).

### Who can duplicate

Duplicating creates a rule and its components, so it requires the capability to create each of
them: `createrule`, plus `createcondition` and `createaction` when the rule has conditions or
actions. The copy control is only shown to roles that hold what the copy would create.
