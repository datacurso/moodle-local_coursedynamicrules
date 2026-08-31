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
