<!-- Replaces the tail of "### Enable activity" in README.md, after the existing
"Action configuration" step 1. Keep the existing screenshots; add the two new subsections. -->

#### Two actions cannot open the same activity

An `Enable activity` action opens an activity by adding an access restriction to it that lists
the users who may enter. Moodle requires **every** restriction on an activity to be satisfied, so
if two actions each add their own, a student sees the activity only once both have opened it for
them. A second restriction is empty until its own rule runs, which means adding it takes the
activity away from the students the first action had already opened it for, from the moment the
form is saved.

The form therefore refuses an activity that another action already opens, and names it:

![Activity already opened by another action](__docs/images/local_cdr_enable-activity-alreadygated.png)

- The refusal applies whether the other action belongs to the same rule or a different one.
- An action is never refused the activities it already opens, so you can keep editing a
  selection you already saved.
- Releasing the activity from the other action is only possible while that action's rule has
  never been activated. Once the rule is activated, its actions cannot be changed, so choose a
  different activity for the new action.

**Two cases the form cannot see.** An access restriction written by a version earlier than 1.8.2
carries no owner mark, so on a site upgraded from those versions the pair can still be created.
And the check lives on the form, so a course restore, or a script, can still produce it.

#### When no activity is selected

An action with nothing selected reads `Enable activities: none chosen yet`. This is the state the
copy of such an action is born in, and the one thing to fix before activating the copy: a rule is
refused activation while any of its `Enable activity` actions has nothing to open.
