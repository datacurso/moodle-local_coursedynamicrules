## Unreleased

## Added
- **Edit existing rule conditions and actions**
  Every condition and action type can now be edited in place from the conditions/actions listing (a new edit control next to delete), reusing the same creation form, preload and validation, without recreating the row or disturbing sibling components or runtime state (execution throttling, already-granted activity access). Editing a foreign or tampered component id is rejected before any form render or write.

---

## 1.7.0

**Released on:** 2026-07-23

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Security
- **Cross-course access on rule management pages**
  Rules, conditions and actions were loaded by id only while capabilities were checked against the requested course, allowing a user with management capability in one course to view, edit, delete or move another course's rules by tampering with the URL or the edit form's hidden id. A new ownership helper now confirms the object belongs to the requested course on the edit and delete pages, the course id is forced when saving a rule, and the submitted rule id is re-validated against the course before an update so a tampered hidden id cannot overwrite or move another course's rule.

## Added
- **Privacy provider**
  Added a null privacy provider declaring that the plugin stores no personal data.
- **Course deletion cleanup**
  Deleting a course now removes its rules, conditions and actions instead of leaving orphaned rows.

## Changed
- **"No course access" measured from enrolment for users who never accessed**
  A user who has never accessed the course is now considered inactive only after the configured period has elapsed since their enrolment, instead of matching immediately.
- **All rule conditions are evaluated on every trigger**
  A rule's conditions are always assessed together as an AND regardless of which trigger fired, so a mixed rule no longer fires when only the event-related condition is met, and a rule spanning two activities can now be satisfied. A relevance check prevents a rule from firing on unrelated events.
- **Clearer condition and interval help text**
  The help now explains that all conditions in a rule are combined with AND (and that OR is modelled with separate rules), and states the expected format for the period and interval fields.

## Fixed
- **Invalid condition inputs were accepted**
  The inactivity period, the custom and recurring intervals, and the selected activity are now validated on the form and rejected on save, and evaluation guards against invalid stored data (avoiding a division-by-zero that could abort the scheduled task and a period that matched every user).
- **Ungraded users matched a "grade less than" condition**
  A missing or null grade is no longer treated as satisfying a grade threshold.
- **Duplicate and inappropriate recipients in scheduled tasks**
  Course users are now selected deduplicated and active-only, so a user enrolled by several methods is actioned once and suspended or deleted users are excluded.
- **Enrolment base date for course inactivity**
  The base date is resolved deterministically from the earliest effective enrolment start, so multiple enrolments no longer raise an exception and an unset start date no longer anchors intervals at the unix epoch.
- **Enable activity action hardened against deleted or edited modules**
  The action no longer fails fatally when a target module was deleted or its access restriction changed, it now locates its own user restriction by type instead of assuming it is the first restriction (so adding another restriction to the activity no longer stops it granting access), and a rule referencing a deleted module can always be removed.
- **Grade event with a deleted grade no longer crashes the rule task**
  Resolving the activity of a grade-triggered rule now returns nothing when the grade row no longer exists, so the rule is simply treated as not relevant instead of raising a fatal error the task cannot recover from.
- **Inactivity "from course start" rejected on courses without a start date**
  Configuring a course inactivity condition anchored to the course start date on a course that has no start date is now rejected on the form with a clear message, instead of being saved as a rule that silently never fires.

## Known limitations
- **Repeated AI reinforcement activities on re-grading**
  Re-grading a student who already meets a rule's conditions can create an additional AI reinforcement activity, because the create-AI-activity action is not yet idempotent. Idempotency is planned for a future release.

---

## 1.6.3

**Released on:** 2026-05-08

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Fixed
- **TypeError in no_complete_activity condition when completion tracking is disabled**
  Fixed a fatal `TypeError` thrown by the scheduled task when a user had visited an activity that has completion tracking disabled (`COMPLETION_TRACKING_NONE`). The Moodle `completion_info::get_data()` RIGHT JOIN returns `completionstate = NULL` in that scenario; the condition now guards against a null value and returns `false` early instead of crashing.

---

## 1.6.2

**Released on:** 2026-04-24

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Changed
- **Notification audience model clarified**
  The send notification action now distinguishes between **primary recipients** and **copy recipients** so the target user and observer users receive the correct message format.
- **Configuration UI improved**
  Notification targeting now uses explicit recipient groups with clearer help text and student selected by default as a primary recipient.

## Fixed
- **Wrong user placeholders in notifications**
  Fixed cases where notification placeholders could be rendered with incorrect user data when different role combinations were selected.
- **No-course-access delivery semantics**
  Notifications are now sent only when the matched user belongs to primary recipient roles, while copy recipients receive an observation message.

## Added
- **Upgrade migration for legacy role params**
  Added upgrade logic to migrate legacy `roleids` (and interim keys) into `primaryroleids` and `copyroleids` without data loss.
- **Automated coverage for migration and behavior**
  Added PHPUnit migration tests and Behat end-to-end scenarios covering no-course-access plus send-notification combinations with exact user-visible assertions.
