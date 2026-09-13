## 1.8.6

**Compatibility note:** This version is compatible only with **Moodle 4.5**.

## Added
- **The engine switching a rule off is recorded in the log**
  A one-shot rule deactivates itself right after it runs, and that write produced no log entry at all: the rules list carried a badge for it, but a log report asked what had happened to the rule had nothing to show, so an automatic stop could only be told from a teacher's pause by inference. The write is now recorded as its own event, `rule_autodeactivated`, distinct from the update event an edit produces. Only the stop is filed under it: reactivation is not an engine decision, and recording it here would answer "who stopped this rule?" with the opposite of the truth.

## Fixed
- **An activity is no longer closed by a rule nobody activated**
  The enable-activity action writes its gate - a user restriction holding an empty list of students, combined with the rest by AND and set not to show itself - into the activity's own access restrictions, and it wrote it the moment the operator chose the activity. Moodle requires every restriction to be satisfied, and that list starts empty until a run fills it, so the activity was not merely locked but hidden from every student as soon as the action was saved: for a rule the operator had never activated, that had never run, and that the listing correctly reported as inactive. It had behaved this way since the action was introduced in December 2024, because nothing at that point ever consulted whether the rule was in force. The gate is now written when the rule is activated, from the single endpoint that activates one, so a rule nobody activated changes nothing. Switching a rule off afterwards deliberately leaves the gate where it is: the ids of the students the rule had already let in are stored inside that gate and nowhere else, so removing it would revoke every one of them in silence and, at the same time, open the activity to students who never met the condition. An activity already closed this way by an inactive rule stays closed until its action is edited or deleted - the fix stops this happening, it does not undo what has already happened.
- **The pass-grade condition no longer accepts an activity that is being deleted**
  Moodle keeps a module in the course while its deletion runs in the background, and this condition's own evaluation already treats such an activity as gone - so a condition saved in that window could never be met, and because a rule requires ALL of its conditions, the whole rule fell silent. Completeness counts conditions by existence, so such a rule could still be activated and sealed, after which it can neither be edited nor, by its own teacher, deleted. Of the plugin's seven activity pickers this was the only one that never filtered them out: the check was added to the others in December 2024 and this form, which predates that sweep, was missed. The picker now omits them, the server refuses one on save even when the form was opened before the deletion started, and editing a condition whose stored activity is gone or being deleted says so on the form instead of opening a blank picker.
- **The enable-activity action no longer names or opens an activity whose deletion is in progress**
  Moodle keeps a module in the course while its deletion runs in the background. The check that decides whether a rule may be activated already excluded such an activity, but the action's description went on naming it as a live target, and its run went on writing the student's id into that activity's restrictions - so the operator could be told the component could never take effect while the engine was still making it take effect. The description now treats it as gone, borrowing the "no longer available in this course" warning the activity conditions use, and the run skips it. The cleanup that removes a deleted user's id is deliberately left unfiltered: erasing an id from an activity on its way out is still right, while granting access on one is not.
- **The actions page announces its own add control**
  The control that opens the list of components to add is shared by the conditions and the actions page, and its accessible label was fixed to "Add conditions": on the actions page a screen reader announced a control for adding conditions, on a page where no condition can be added. Each page now supplies its own label.
- **A rule can no longer act on an activity in another course**
  The enable-activity action asks the database for an activity by its id, and those ids are unique across the whole site rather than within a course. Three of its operations did not also check that the activity belongs to the rule's own course, so a rule holding an id from elsewhere could open that activity for one of its own students, erase a restriction its own teacher had set there, and change whether it is visible on the course page - none of which anybody had asked for, in a course nobody had touched. A rule can end up holding such an id after a course is restored as a new course with one of its activities left out: the activity is not recreated, so the action keeps pointing at the original, and a rule that was active when it was backed up comes back active. All three operations now check the course, and an activity that is not part of it is skipped with an explanation in the developer log.
- **Each action form lists the markers its own action replaces**
  The notification body and the AI prompt both offer a list of markers to paste, and both took that list from the same template, which named a fixed set. One of the two was therefore always wrong: the AI form offered a course-link marker its action does not replace - it replaces a plain course URL instead - so a teacher who copied what the form offered sent the marker's literal text to the model. Each action now declares the markers it replaces, next to the code that replaces them, and each form asks its own action.
- **The edit pencil is not offered when the plugin an action needs is missing**
  Two of the action forms stop building themselves as soon as a plugin they depend on is absent, showing an explanation and no fields. The listing offered its edit pencil anyway, so the control promised an edit it could not deliver and the teacher arrived at a dead end. Each action now declares what it depends on, next to itself rather than inside its form, and the listing asks before offering. Deletion is deliberately still offered: an action whose dependency has gone must remain removable, or it stays in the rule forever.
- **A rule refused activation says which requirement is missing**
  Four different situations stop a rule being activated: it has no condition, it has no action, one of its actions belongs to a plugin this site cannot load, or one of its actions could do nothing if it ran - an "enable activity" action with no activity chosen, or with every chosen activity deleted. All four were answered with the same message, which listed every requirement at once and left the teacher to work out which sentence was about them. Each situation now has its own explanation, on the rule form and on the activation page alike.
- **Two actions can no longer end up sharing one activity because Moodle forgot who owns its gate**
  Each "enable activity" action writes its own gate into the activity's access restrictions, and Moodle requires every gate to be satisfied - so a second gate, empty until its own rule runs, closes the activity for the students the first one had already opened it for. The plugin refuses to add that second gate, but it recognised the first one by an owner mark stored inside those restrictions, and Moodle erases that mark: opening the activity's settings and saving is enough, once the gate has students in it. The refusal then stopped refusing, silently. It now also asks the actions themselves which activities they manage - the record they already work from - so the mark being erased no longer blinds it. Both sources are consulted, because the mark still catches a gate its own action no longer lists.

## Known limitations
- **Activity pickers and deletion in progress**
  The pass-grade picker no longer offers such an activity and now refuses it on save (see Fixed), but the other three condition forms only filter their picker: their server-side validation still accepts an activity whose deletion started while the form was open, so a form submitted during the recycle-bin window can still save a condition that is a ghost from birth.
- **An action's description hides a partial loss**
  The enable-activity action omits from its description an activity that was deleted or whose deletion is in progress, so an action with three targets of which one is gone reads as if it only ever had two; only the case where EVERY target is gone is flagged, with the shared missing-activity warning. The create-AI-activity action drops the placement clause when its anchor activity was deleted and still hands the stale id to the generator, which then appends the new activity at the end of the section.
- **The engine's own stop is not carried into a restored course**
  Every event this plugin raises now tells the restore how to translate the rule, condition or action it points at, so a restored course's log entries refer to its own components instead of the source site's. The one exception is the entry recording that the engine switched a rule off: it is attributed to the system rather than to a person, and Moodle drops a log record whose actor it cannot match to a user in the destination site before it ever looks at what the record points at. So that single entry does not survive a course copy. Making it survive would mean putting a person's name on a decision no person took, which is what the entry exists to avoid; Moodle's own grade engine accepts the same trade for the same reason.

## 1.8.5

**Released on:** 2026-09-11

**Compatibility note:** This version is compatible only with **Moodle 4.5**.

## Fixed
- **Course backup no longer fails when another plugin uses the same generic element names**
  Backing up a course could fail with a "duplicate element" error whenever another installed plugin's course data happened to use the same generic names as this plugin's (rules, conditions, actions, and so on) - Moodle requires every plugin's backup element names to be unique site-wide, not only within its own structure. Every element this plugin writes to a backup now carries a plugin-specific name, so that collision cannot happen again. Backups made before this fix continue to restore exactly as before, with no action needed from anyone holding an older archive.

## 1.8.4

**Released on:** 2026-09-09

**Compatibility note:** This version is compatible only with **Moodle 4.5**.

## Added
- **Deleting a sealed rule takes the manager key**
  The editing teacher deletes what they build: conditions, actions, and rules that were never activated. A **sealed** rule (activated at least once - it has run against students) keeps deletion as its one exit, and that exit now additionally demands the new manager-only `deletesealedrule` capability: the teacher's trash can appears only on rules that were never activated, the manager sees it everywhere, and the endpoint refuses a direct URL under the same pair. Being a new capability, it reaches existing sites through the standard capability update with no upgrade step, and a role where an administrator explicitly decided otherwise keeps that decision.
- **A rule can be duplicated into an editable draft**
  A copy control on the rules list clones a rule with all its conditions and actions into a new rule named "{name} (copy)" - shortened at the end when name and suffix together would not fit the field, so the suffix survives. The copy of an enable-activity action starts with no activities selected: a second gate on the same activity would close it to the students the original opened it for, so the teacher picks the activities on the draft, and duplicating or discarding the copy never touches what the original manages. The copy is born inactive and unsealed with its runtime state left behind - which makes duplication the official escape hatch of the activation lock: the ideas of a sealed rule can be copied, edited freely and activated deliberately through the confirmation flow, once the activities its enable-activity actions manage have been chosen again. Duplicating creates the rule AND its components, so it takes the capability each of those takes when added by hand: a role that may create rules but not conditions or actions is not offered the control on a rule that has them, and the endpoint refuses it. Traced from Moodle Workplace's dynamic rules, which suggest exactly this path for locked rules.

## Changed
- **An "enable activity" action with nothing to show says why**
  Such an action described itself as `Enable activities ''` - empty quotes - whether no activity had been chosen yet or every chosen activity had since been deleted. It now reads "Enable activities: none chosen yet" in the first case, which is the state every copy is born in and the one thing the teacher must fix, and borrows the activity conditions' "no longer available in this course" warning in the second.
- **Two actions can no longer open the same activity**
  Each enable-activity action writes its own gate into the activity's restrictions, and Moodle requires EVERY gate to have opened it for a student: a second gate, which is empty until its own rule runs, therefore closed the activity for the students the first action had already opened it for, from the moment the form was saved. The form now refuses an activity another action already opens, names it, and says so. Two exceptions, both deliberate: an action keeps whatever it already gates, so a pair created by an earlier version stays editable on both sides, and a gate written before 1.8.2 carries no owner mark, so it is invisible to this check (see Known limitations). **Site administrators, note:** where such a pair exists, releasing the activity from one of the two actions is only possible while that action's rule has never been activated; a sealed rule cannot give it up.
- **A rule cannot be activated while one of its actions could do nothing**
  The activation check now asks every action whether it could act at all, instead of counting rows. Only the enable-activity action can answer no, and it does so in two states: no activity chosen, which is how the copy of such an action is born, and every chosen activity deleted since, which nothing cleans up. Either way the rule reports as incomplete, because activation is permanent: it would otherwise be sealed with an action that grants nobody, and a sealed rule can neither be edited nor, by its own teacher, deleted. Choose the activities first, then activate.
- **The activity conditions no longer say "course activity module"**
  The three conditions that used that qualifier - completed, not completed, and completed with a passing grade - described the activity as the "course activity module" in every language. The activity's own name is what tells the condition apart, so the qualifier only added words. The pickers' own labels still carry it - "Search course activity modules" and "All course activity modules" - so the two wordings coexist on the form until that sweep is finished.

## Fixed
- **Pausing a rule by hand no longer shows the Executed badge**
  The list badge told an engine-executed rule apart from a hand-paused one only by proxy - whether the rule had ever run. That proxy was wrong: an event-driven rule records an execution time on every run yet is never switched off by the engine, so pausing it by hand wrongly showed **Executed** instead of **Paused**. The rule now records the one moment that earns Executed - the engine deactivating a one-shot scheduled rule right after it ran - in a dedicated stamp, and any manual toggle of the Active box clears that stamp. So Executed marks only a rule the engine stopped, and a rule a person pauses always reads as Paused. **Site administrators, note:** the upgrade adds the stamp empty for the whole installed base, so a rule that was already stopped reads as Paused until its next engine deactivation - a historical Executed cannot be reconstructed and is not guessed at.
- **A grade condition keeps working after its activity's grade item is recreated**
  A "grade in activity" condition stored each threshold against the grade item's database id. Moodle recreates that id whenever the activity's grade settings are edited or the course is restored, which orphaned the stored threshold: the rules list showed the condition with no value and no "greater/less than", and - worse, and silently - the rule stopped firing altogether, because the evaluation looked the threshold up by the new id and found nothing. Thresholds are now keyed by the grade item's stable itemnumber, so both the listing and the evaluation survive the id changing. Conditions saved before this fix are read back by position, so an existing single-item condition is revived without any migration; editing and re-saving it rewrites it in the stable shape.
- **Inactivity rules with the "from now" base date now measure from the rule's activation**
  The base date was recomputed as the current time on every evaluation, so the milestones moved with the clock: a recurring interval was satisfied on every cron pass and notified an inactive student every 6 hours, while custom intervals always landed in the future and never fired. "From now" now means the moment the rule was FIRST activated, read from the activation stamp introduced in 1.8.3 - pausing and reactivating does not move it - so the intervals stay anchored, and a recurring rule no longer fires inside the first interval after its anchor - nothing has elapsed yet. That first-interval guard applies to every base date: a recurring rule anchored on the enrolment or course start date also stops notifying a never-accessed student on the task run right after that date. Conditions saved before this fix keep their stored value and pick up the corrected meaning without re-saving. **Site administrators, note:** a rule that was already active when 1.8.3 was installed carries the stamp that upgrade backfilled - its last-modified date, falling back to its creation date and then to the upgrade moment, not a real activation - so its "from now" milestones are measured from that date. A recurring rule simply continues on that grid; a custom-interval rule whose milestones all fall before today will not fire again, and because an activated rule is sealed it cannot be edited: recreate it to start a fresh count. The same applies to a course restored, imported or duplicated from a backup: the copy keeps the source rule's activation date, so its "from now" intervals are measured from that date, not from the copy's own start.
- **A component whose activity was deleted stays visible and can be removed**
  A condition pointing at an activity that was since deleted from the course returned an empty description, and every listing skips components without one: the card vanished from the conditions page and from the rules list while its row stayed in the database - evaluating false forever and unreachable by the only trash can the operator has. Such a ghost now describes itself with a warning ("The selected activity is no longer available in this course, so this component can never take effect") in every listing and on the delete confirmation page, keeps its id and, on a rule that was never activated, its trash can. An activity whose deletion is still in progress through the recycle bin is treated as gone in these descriptions, as the evaluation already did. Editing a ghost no longer re-points the rule: every condition's activity picker now opens on a blank choice, when creating a condition as much as when the stored activity is gone - a browser shows the first option of an unmarked list as if it were chosen, and Save used to store it - and saving without an activity is refused with an error next to the picker. The grade condition's picker additionally lost its old habit of preselecting the first eligible activity, and its refusals are now shown on the form instead of reloading it in silence. **Site administrators, note:** components that were silently absent from listings on existing sites now appear with this warning; on a rule that was already activated the ghost is visible but, like every component of a sealed rule, cannot be deleted on its own - deleting the rule is the one exit.
- **Deleting a user removes them from the activities a rule opened for them**
  The enable-activity action grants a student access by writing their id into the activity's user restriction, and nothing removed it when the site deleted the user: Moodle's own user restriction declares no personal data and does not react to a deletion, so the id stayed behind, and the restriction went on displaying the deleted person's name. The plugin now listens to the site's user deletion and scrubs the id from every restriction node the enable-activity action owns - found by the same rule the grant uses, so a restriction a teacher added by hand is left exactly as it was - on sealed rules as much as on open ones, because the cleanup is a runtime write like the grant itself, not an edit. A rule task that was queued before the deletion no longer acts for the deleted user either, so it cannot write the id back.
- **A rule name longer than its field is refused on the form**
  The name field had no length limit, so a name over 255 characters went through to the database and came back as a write error. The field now stops at 255 characters in the browser, and the form refuses a longer name on the server with the standard message.

## Known limitations
- **A ghost component still counts towards a rule's completeness**
  The activation check counts conditions by existence alone, so a condition pointing at a deleted activity still counts, and such a rule can be activated and sealed although it can never fire. It is at least visible: a ghost condition describes itself with a warning in every listing, so the operator can see it - and reach its trash can - before activating. Whether completeness should refuse it as well is a separate product decision. Actions are not counted this way: each one is asked whether it could act at all, so the same gap on the action side is closed - see the Changed section.
- **Actions do not flag deleted activities**
  The enable-activity action silently omits deleted activities from its description, so an action with three targets of which one was deleted reads as if it only ever had two, and it still names activities whose deletion is in progress; its run does not skip an activity whose deletion is in progress either. Only the case where EVERY target is gone is flagged, with the shared missing-activity warning. The create-AI-activity action drops the placement clause when its anchor activity was deleted and still hands the stale id to the generator, which then appends the new activity at the end of the section. Both are out of the 1.8.4 scope.
- **Activity pickers and deletion in progress**
  The pass-grade activity picker still offers an activity whose deletion is in progress, and the pickers' server-side validation accepts such an activity, so a form submitted during the recycle-bin window can save a condition that is a ghost from birth.
- **Two blank rows in the activity pickers' suggestion list**
  Moodle's autocomplete widget adds a blank option of its own to every single-choice picker, and the blank choice these pickers now start on sits next to it: the suggestion list opens with one empty row until an activity is chosen and two afterwards, and a course with no eligible activity shows a single empty row where the widget would otherwise say "No suggestions". Choosing an empty row selects nothing. Cosmetic, left as is.
- **A condition whose activity no longer qualifies gets no notice**
  Only an activity that was deleted, or whose deletion is in progress, earns the "no longer available" notice, and only on the grade condition's edit form. An activity that still exists but no longer qualifies for the condition - its completion tracking was switched off, or it no longer requires a grade or a passing grade - is simply not offered by any of the four pickers: the form opens blank with no explanation, and Save answers "You must select an activity from this course." although the activity is in the course. On the grade condition the notice is also lost once a save was refused, because the refused form comes back with no activity.
- **"From now" inactivity rules and the activation stamp**
  The anchor is the rule's first activation and does not move on pause and reactivation, and a restored, imported or duplicated course keeps the source rule's stamp; a custom-interval rule whose milestones all lie in the past therefore never fires again and, being sealed, can only be recreated. Recurring intervals measured in months drift because the first interval's length is reused for all of them, and a first interval crossing a daylight-saving change desynchronises days and weeks the same way. A recurring rule whose anchor falls exactly on a task run (a course start at midnight) can notify twice for one milestone, and a task run that is skipped loses that milestone: there is no catch-up.
- **Course backup and restore (.mbz) are out of the 1.8.4 scope**
  Rules travel with a course backup and the restore remaps what it can, but several outcomes depend on what the archive contains and on Moodle's own restore order, and none of them is addressed by this release. A rule that was active in the source arrives sealed (its activation stamp is copied, or stamped from its modification date when the archive predates the stamp), so it cannot be edited in the copy - and a "from now" inactivity rule measures its intervals from the source's activation, not from the copy's. A condition whose activity was not included in the archive keeps the source course module id: it arrives as a ghost, visible with the warning above, and on a sealed rule only deleting the rule removes it. An enable-activity target or AI placement anchor with the same fate is dropped silently, as described under "Actions do not flag deleted activities". The ownership markers the enable-activity action writes into activity restrictions are re-adopted after core's restore re-encodes mixed restriction trees; that pass is tested, but a restriction tree with several unmarked user nodes cannot be re-adopted unambiguously. Notification roles with no equivalent on the destination site are dropped with a log entry. Restoring into an existing course in merge mode does not bring rules at all, by design. Treat a restored course's rules as needing review before activation.
- **Descriptions and capitalisation**
  Base-date fragments are lowercased for the sentence they are interpolated into, which lowercases German nouns; the ghost warning renders as plain text in the components card and under the grade condition's picker, with no visual distinction from a healthy description or from the form's other text. The grade condition's refusals are shown under the form but, unlike the other pickers' errors, move no focus and are not announced to assistive technology, and the element that carries them leaves an empty row between the thresholds and the buttons when there is nothing to report.
- **Translations are incomplete outside English and Spanish**
  A number of strings - form validation errors, event names, privacy metadata, the notification recipient fields, the activation-lock messages and the duplication control - exist only in English and Spanish; German, French, Indonesian, Portuguese and Russian fall back to English for them. The two strings this release makes newly visible in the activity pickers were added to all languages. One consequence outlives the fallback: a copy is NAMED in the acting user's language, so on a site without those translations the copy is stored as "{name} (copy)" rather than a translated suffix, and renaming it is the only way to change that afterwards.
- **Two rules opening the same activity**
  The form refuses a second action on one activity (see Changed), but two cases slip past it. A gate written before 1.8.2 carries no owner mark, so on a site upgraded from those versions a new action can still be pointed at an activity an old one opens, and the students the old one had opened it for lose it. And the refusal lives on the form: any caller that saves an action without it - a course restore, a script - can still create the pair. Where a pair exists, a student sees the activity only once BOTH actions have opened it for them, and each action remembers how visible the activity was when it took it over and restores that when it is deleted, so the one deleted last decides.
- **Privacy exports, and the restriction on an AI-generated activity**
  The plugin's privacy provider declares metadata only, so a subject access request exports none of the ids a rule wrote into activity restrictions, and the privacy tool's own data-deletion pass does not touch them either; a deletion request does end by deleting the account, which runs the cleanup above. The create-AI-activity action also restricts the activity it generates to the student it was generated for, in a node it neither marks nor records, so that id is out of the cleanup's reach and stays after the student is deleted. A user restriction a teacher added by hand keeps a deleted user's id, as it always has in Moodle.

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
  A long condition or action description (a notification body, an AI prompt) used to stretch its row and make the rules list ragged. The list now trims the free text each component carries - a notification body, an AI prompt, the list of activities an enable action names - to 80 characters with an ellipsis, so rows keep a similar height, while the conditions and actions pages reached through each rule's magnifier show the full text. Trimming each part at its source rather than the finished sentence is what keeps the message visible: a notification's description opens with a preamble naming its subject and every recipient role, which on its own runs past 190 characters with five roles, so a cut applied to the whole sentence was swallowed before the message began. The cut is made on the plain text before HTML escaping, so no escaped entity is ever sliced in half.

## Security
- **Rule names are escaped on the rules list and the delete confirmation page**
  A rule name was written into both pages without escaping. It could not be exploited through the rule form, which types the field as plain text and strips tags, but course restore writes the name with no cleaning at all - so a rule arriving in a prepared backup rendered as live markup on two pages that only privileged users reach. Both now escape the name through one shared boundary, which also resolves multilang names instead of printing their markup. **Site administrators, note:** this closes a vector present in 1.8.2 and earlier; a course restored from an untrusted backup is the way in, so sites that accept backups from outside should upgrade rather than defer.

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
