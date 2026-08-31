@local @local_coursedynamicrules @local_coursedynamicrules_lock
Feature: A rule can be edited only until its first activation
  In order to trust that a running rule keeps doing what was reviewed
  As a teacher
  I need activation to be a deliberate, explained, one-way door - while pausing stays mine forever

  # The product decision this pins (2026-08-31): activating a rule locks it permanently - name,
  # conditions and actions become unmodifiable. Pausing and reactivating stay allowed, deleting
  # stays allowed, and activation is refused while the rule is incomplete, because a locked rule
  # with no components could never fire and never be finished. The adversarial review of the PLAN
  # found that creating a rule with the box already ticked - the most natural flow there is - would
  # have produced exactly that dead rule; these scenarios are that review, executable.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher1  | User1    | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: A rule cannot be born active
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    When I press "Add rule"
    And I set the field "Name" to "Too eager"
    And I set the field "Active" to "1"
    And I press "Save changes"
    Then I should see "A rule cannot be activated until it has at least one condition and one action"
    # And saving it without activation is the offered path, not a dead end.
    When I set the field "Active" to ""
    And I press "Save changes"
    Then I should see "Too eager"
    And I should see "Inactive"

  Scenario: Activating a complete rule warns, confirms, and locks it for good
    Given the following local coursedynamicrules no course access rules exist:
      | course | name          | active | periodvalue | periodunit | primaryroles | copyroles | subject | body |
      | C1     | Reminder rule | 0      | 1           | days       | student      |           | S       | B    |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "//tr[contains(., 'Reminder rule')]//a[contains(@href, 'editrule.php')]" "xpath_element"
    When I set the field "Active" to "1"
    And I press "Save changes"
    # The save held the activation back: what renders now is the one question, in full.
    Then I should see "You are about to activate this rule"
    And I should see "it can never be modified again"
    When I press "Activate permanently"
    Then I should see "The rule was activated"
    And I should see "Locked"
    And I should see "Active"

  Scenario: Cancelling the confirmation keeps every edit saved and the rule inactive
    Given the following local coursedynamicrules no course access rules exist:
      | course | name       | active | periodvalue | periodunit | primaryroles | copyroles | subject | body |
      | C1     | Draft rule | 0      | 1           | days       | student      |           | S       | B    |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "//tr[contains(., 'Draft rule')]//a[contains(@href, 'editrule.php')]" "xpath_element"
    When I set the field "Name" to "Renamed but cautious"
    And I set the field "Active" to "1"
    And I press "Save changes"
    And I press "Cancel"
    # The rename survived the cancelled activation - edits are never hostage to the confirmation.
    Then I should see "Renamed but cautious"
    And I should see "Inactive"
    And I should not see "Locked"

  Scenario: A locked rule offers no way to modify itself, and pausing still works
    Given the following local coursedynamicrules no course access rules exist:
      | course | name        | active | periodvalue | periodunit | primaryroles | copyroles | subject | body |
      | C1     | Sealed rule | 0      | 1           | days       | student      |           | S       | B    |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "//tr[contains(., 'Sealed rule')]//a[contains(@href, 'editrule.php')]" "xpath_element"
    And I set the field "Active" to "1"
    And I press "Save changes"
    And I press "Activate permanently"
    # The form: name and description are hardFrozen. Boost's element templates render that as the
    # input with readonly AND disabled (lib/form/templates/element-text.mustache) - visible,
    # uneditable, and never submitted; the server-side whitelist covers whatever a stale tab sends.
    When I click on "//tr[contains(., 'Sealed rule')]//a[contains(@href, 'editrule.php')]" "xpath_element"
    Then I should see "This rule was activated and can no longer be modified"
    And "//input[@name='name'][@disabled]" "xpath_element" should exist
    # The components: nothing to add, nothing to delete - even though this teacher holds the
    # delete capability since 1.9.0, a locked rule outranks it. Anchored on OUR hrefs, not on
    # Bootstrap classes: Boost's own drawers use list-group-item-action on every page.
    When I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "//tr[contains(., 'Sealed rule')]//a[contains(@href, 'conditions.php')]" "xpath_element"
    Then "//a[contains(@href, 'conditions.php') and contains(@href, 'type=')]" "xpath_element" should not exist
    And "//a[contains(@href, 'deletecondition.php')]" "xpath_element" should not exist
    # And the one thing that must keep working forever: the pause.
    When I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "//tr[contains(., 'Sealed rule')]//a[contains(@href, 'editrule.php')]" "xpath_element"
    And I set the field "Active" to ""
    And I press "Save changes"
    Then I should see "Inactive"
    And I should see "Locked"

  Scenario: Replaying the activation confirmation on a sealed rule tells the truth
    # Both judges flagged the replay lie: back button, double click or an old tab reaches the
    # confirmation of a rule that already sealed, and the page either asked the irreversible
    # question again or fell through to the edit form in silence. It must say what happened.
    Given the following local coursedynamicrules no course access rules exist:
      | course | name         | active | timeactivated | periodvalue | periodunit | primaryroles | copyroles | subject | body |
      | C1     | Already done | 1      | 1700000000    | 1           | days       | student      |           | S       | B    |
    And I log in as "teacher1"
    When I visit the activation confirmation page for the rule "Already done"
    Then I should see "This rule has already been activated"
    And I should not see "You are about to activate this rule"

  Scenario: A sealed rule offers viewing its components, never editing them
    # Both judges, round 2: the empty-column add links were muted for sealed rules, but a sealed
    # rule WITH components kept a pencil promising "Edit conditions"/"Edit actions" - a link into
    # pages where nothing can be added, deleted or edited. The navigation is legitimate (seeing
    # what a running rule does matters); the promise to edit is not. Sealed rules get a view
    # affordance instead.
    Given the following local coursedynamicrules no course access rules exist:
      | course | name          | active | timeactivated | periodvalue | periodunit | primaryroles | copyroles | subject | body |
      | C1     | Sealed viewer | 1      | 1700000000    | 1           | days       | student      |           | S       | B    |
    And I log in as "teacher1"
    When I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    Then "Edit conditions" "link" should not exist
    And "Edit actions" "link" should not exist
    And "View conditions" "link" should exist
    And "View actions" "link" should exist
    # And the viewing navigation actually works: the eye lands on the conditions page.
    When I click on "View conditions" "link"
    Then "//body[@id='page-local-coursedynamicrules-conditions']" "xpath_element" should exist

  Scenario: A sealed rule with no components is not offered the add links
    # The upgrade seals every active rule, including ones the pre-lock form allowed to be active
    # with zero components. The listing offered those "Add conditions"/"Add actions" links gated
    # only by capability - a live link into a page where adding is refused. The fact (no
    # components) stays visible; the dead offer goes.
    Given the following local coursedynamicrules bare rules exist:
      | course | name         | active | timeactivated |
      | C1     | Sealed empty | 1      | 1700000000    |
    And I log in as "teacher1"
    When I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    Then I should see "Add conditions"
    And I should see "Add actions"
    And "//tr[contains(., 'Sealed empty')]//a[contains(@href, 'conditions.php')]" "xpath_element" should not exist
    And "//tr[contains(., 'Sealed empty')]//a[contains(@href, 'actions.php')]" "xpath_element" should not exist
