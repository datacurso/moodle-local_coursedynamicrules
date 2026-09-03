## 1.8.3

**Released on:** 2026-09-02

**Compatibility note:** This version is compatible only with **Moodle 4.5**.

## Added
- **An editing teacher can delete rule components and rules**
  The three delete capabilities were manager-only, so a component created by mistake meant an escalation request to an administrator - multiplied by every teacher on the site. Whoever may build rules may also unbuild them: the editing teacher archetype now holds `deleterule`, `deletecondition` and `deleteaction`, on fresh installs and through the upgrade step on existing sites alike. A role where an administrator explicitly prohibited any of these keeps that decision - the upgrade does not overrule it.
- **A rule becomes permanently unmodifiable at its first activation**
  Activating a rule is now an explicit, one-way step. Saving with the Active box ticked stores every edit first and then asks for confirmation on its own page, spelling out that the rule can never be modified again and that pausing carries its own risks; replaying that confirmation later simply reports the rule is already activated. Once confirmed, the rule's name, description, conditions and actions are sealed: the form freezes, the add and delete controls disappear, direct URLs are refused, and the server re-decides at write time so a tab opened before the seal cannot smuggle an edit through. Pausing, reactivating and deleting remain available forever - the list shows one badge with four states: **Active** (running, whether or not it has fired - event-driven rules stay active and fire repeatedly), **Executed** (stopped and already fired at least once, in the Datacurso brand orange - one-shot scheduled rules land here on their own, because the task deactivates them right after executing), **Paused** (activated once, stopped, never fired - resumable forever) and **Inactive** (never activated, the only editable state) - and a rule with no conditions or actions cannot be activated at all - sealed incomplete could never fire nor be finished. The seal survives course backup, restore, import and duplication, and an archive made before this version restores its active rules sealed. **Site administrators, note:** the upgrade seals every rule that is active at upgrade time - active means it was activated once - while inactive rules stay editable until their first activation.
- **Conditions and actions can be edited in place while the rule was never activated**
  The 1.8.1 withholding of in-place editing existed because editing could change what an already-running rule did to learners; the activation lock dissolved that risk, so the editor returns exactly inside the bound: a pencil on each condition/action card, shown only while the rule was never activated and only to roles holding the matching `update*` capability (both re-checked server-side with ownership before anything renders). The form opens preloaded with the stored configuration, saves preserve runtime state, and edits fire the `condition_updated`/`action_updated` audit events.

- **A rule's description is revealed by hovering its name on the list**
  The description was written on the rule form and then visible nowhere else, so telling two similarly named rules apart meant opening each one. The name on the rules list now carries the description as a tooltip, shown only for rules that have one, without spending a column on it. Note the limit: a native tooltip answers to the mouse only, so it is not reachable by keyboard or on a touch screen - the edit form remains the way to read a description without a pointer.
## Changed
- **Declared capabilities are now enforced where their pages and controls live**
  Entering the rules, conditions and actions pages now requires the matching `view*` capability alongside the `manage*` one; adding a component requires `create*` both on the menu and on the URL it posts to; controls that would be refused are no longer offered - including the per-row delete controls, which are shown only to roles holding the matching `delete*` capability. **Site administrators with custom roles, note:** a custom role built without an archetype that was granted only `manage*` capabilities - previously the only ones checked - must now also be granted the matching `view*` (and `create*`, if it adds components) or it will lose access to these pages on upgrade. Roles based on the editing teacher or manager archetypes are unaffected. The `updateaction` and `updatecondition` capabilities are now enforced too: they gate the in-place component editor described under Added.
- **Both component listings warn when the availability_user plugin is disabled**
  Losing the per-user restriction silently un-hides every activity the rules gate, so the operator is told where they work instead of discovering it through exposed content.
- **Declared dependencies match the APIs actually used**
  `local_coursegen` moves to 2026082400 and `aiprovider_datacurso` to 2026081000. Without this the plugin would install against a Course Creator AI that does not have `create_mod_service`, and break in exactly the way this release fixes.
- **Component descriptions are trimmed on the rules list and shown whole on the component pages**
  A long condition or action description (a notification body, an AI prompt) used to stretch its row and make the rules list ragged. The list now trims every description to 220 characters with an ellipsis so all rows keep the same height, while the conditions and actions pages reached through each rule's magnifier show the full text. The budget covers a notification's fixed preamble - its subject and recipient roles run to about 146 characters on their own - so the message body is still visible on the list, as it was in 1.8.2. The cut is made on the plain text before HTML escaping, so no escaped entity is ever sliced in half.

## Fixed
- **The create AI activity action works again with Course Creator AI 2.x**
  Course Creator AI 2.0.3 removed `local_coursegen\mod_manager` without leaving an alias, so the action called a class that no longer existed. The failure was swallowed by the action's own error handling and surfaced only as developer debugging output, which meant that on a site with debugging off the action simply produced nothing, silently, on every run. The action now goes through `local_coursegen\local\service\create_mod_service` and unwraps the flat result the service returns, instead of the nested shape the removed class expected.
- **The required-plugin checks on the AI action form name the right plugins**
  The form pointed at the wrong download page for Course Creator AI and did not check for `aiprovider_datacurso` at all, even though that plugin supplies the HTTP client the action depends on.
- **Saving a rule enforces the capability on the rule actually written**
  The page decided between `createrule` and `updaterule` from the id in the URL, but wrote to the id in the form - a hidden, client-controlled field. A role allowed only to create could update an existing rule by posting its id, and a role allowed only to update could create by posting zero. The capability is now decided where the write target is resolved, on the id that is actually written, and omitting the context there is a fatal error rather than a silent skip.
- **The create-a-rule form no longer emits PHP warnings**
  Building the form for a rule that does not exist yet read three properties off an empty object. Invisible in production; fatal under acceptance testing, where it took the whole rule form screen down.
- **Learner names are anonymised as whole words only**
  A name that is the prefix of another word (such as "Eva" inside "Evaluación") is no longer mangled in the prompt sent to the AI service, and the full name is replaced before its parts.
- **Restoring a course reconciles notification roles and ownership markers**
  Role ids stored inside notification actions are remapped to the restored course's roles, and ownership markers survive the round trip.

---

## 1.8.2

**Released on:** 2026-09-01

**Compatibility note:** This version is compatible only with **Moodle 4.5**.

### Fixed
- **Send notification action can now target copy recipients only**
  Saving a send notification action required at least one primary recipient role, and executing it never notified copy recipients unless a primary role also matched - so a rule meant to notify only an observer role (for example, a teacher) about another role's activity, without messaging that role directly, could not be configured at all. Primary recipients are now optional: at least one recipient role, primary or copy, must be selected, and a copy-only configuration notifies its copy roles without ever messaging the matched user.

---

## 1.8.1

**Released on:** 2026-07-31

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Changed
- **Editing an existing condition or action is unavailable**
  The edit control has been removed from the conditions and actions lists, and the editor cannot be reached by a direct link or a bookmark either: any such request returns to the list with a notice. Editing changes what an already-running rule will do to learners, so it is withheld until the advisory messages that must accompany it are in place. Adding and deleting components are unaffected, and no stored rule, condition or action is modified by this upgrade.

---

## 1.8.0

**Released on:** 2026-07-30

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Added
- **Edit existing rule conditions and actions**
  Every condition and action type can now be edited in place from the conditions/actions listing (a new edit control next to delete), reusing the same creation form, preload and validation, without recreating the row or disturbing sibling components or runtime state (execution throttling, already-granted activity access). Editing a foreign or tampered component id is rejected before any form render or write.
- **Audit events for edits**
  Editing a condition or action now triggers a dedicated `condition_updated`/`action_updated` Moodle event (mirroring the existing created/deleted events), so in-place edits appear in the logs and reports instead of going unaudited or being reported as a new component.

---

## 1.7.1

**Released on:** 2026-07-29

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Added
- **Audit events**
  Creating, updating and deleting rules, conditions and actions now trigger Moodle events so these operations appear in the logs and reports.

## Changed
- **Privacy provider declares the external AI transfer**
  The privacy provider now declares, through the Privacy API, the course context and user id sent to the external Datacurso AI service when the create AI activity action runs, instead of declaring that no data leaves Moodle.
- **Scheduled task observability**
  The "no course access" and "no complete activity" tasks now report per-run counts and duration, and warn when a course exceeds a configurable batch threshold (`taskbatchsize`), without changing their cadence or evaluation semantics.

---

## 1.7.0

**Released on:** 2026-07-28

**Compatibility note:** This version is compatible with **Moodle 4.5**.

## Security
- **Cross-course access on rule management pages**
  Rules, conditions and actions were loaded by id only while capabilities were checked against the requested course, allowing a user with management capability in one course to view, edit, delete or move another course's rules by tampering with the URL or the edit form's hidden id. A new ownership helper now confirms the object belongs to the requested course on the edit and delete pages, the course id is forced when saving a rule, and the submitted rule id is re-validated against the course before an update so a tampered hidden id cannot overwrite or move another course's rule.
- **Stored XSS in configurable rule descriptions**
  Rule, condition and action descriptions embed user-configurable text (such as the notification subject and body, the AI activity prompt, and role or activity names) and were rendered without escaping on the rules list and the delete confirmation pages, allowing stored HTML or JavaScript to execute in another user's browser. A new component renderer escapes every description at the rendering boundary before it reaches the page.

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
