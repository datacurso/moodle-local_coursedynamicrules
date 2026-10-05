@local @local_coursedynamicrules @local_coursedynamicrules_duplicate
Feature: A rule can be duplicated into an editable draft
  In order to reuse a rule's ideas, sealed or not
  As a teacher
  I need a copy control that creates an inactive copy I can edit, offered only to those who may create rules

  # The sealed-rule case, the lock's escape hatch, lives in rule_activation_lock.feature. These
  # scenarios cover the everyday copy of an open draft and the capability that offers the control:
  # duplicating creates a rule, so it takes the create capability, whatever else the teacher may do.

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
    And the following local coursedynamicrules no course access rules exist:
      | course | name        | active | periodvalue | periodunit | primaryroles | copyroles | subject | body      |
      | C1     | Draft ideas | 0      | 12          | weeks      | student      |           | Nudge   | Body text |

  Scenario: An open draft is duplicated with its own conditions and actions, and the source is untouched
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    When I click on "Duplicate rule" "link"
    Then I should see "Rule duplicated"
    # The copy: its own row, inactive, carrying a condition and an action of its own - not a link
    # back to the source's, which "I should see Draft ideas (copy)" alone could never tell apart
    # from a substring match inside the source's own row.
    And "//tr[contains(., 'Draft ideas (copy)') and contains(., 'Inactive')]" "xpath_element" should exist
    And "//tr[contains(., 'Draft ideas (copy)') and contains(., '12') and contains(., 'weeks')]" "xpath_element" should exist
    And "//tr[contains(., 'Draft ideas (copy)') and contains(., 'Nudge')]" "xpath_element" should exist
    # The source: its own row, distinguished from the copy's by the ABSENCE of the suffix, still
    # carrying the condition and the action it had - not merely proving A row named "Draft ideas"
    # exists, which the copy's own row already satisfies.
    And "//tr[not(contains(., '(copy)'))][contains(., 'Draft ideas')][contains(., '12')][contains(., 'Nudge')]" "xpath_element" should exist

  Scenario: Without the capability to create the rule's components, the copy control is not offered
    # Duplicating creates this rule's condition and action too, so it takes the capabilities adding
    # them by hand takes. The icon is hidden under exactly what duplicaterule.php refuses.
    Given the following "permission overrides" exist:
      | capability                               | permission | role           | contextlevel | reference |
      | local/coursedynamicrules:createcondition | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    Then I should see "Draft ideas"
    And "Duplicate rule" "link" should not exist

  Scenario: Without the create capability the copy control is not offered
    Given the following "permission overrides" exist:
      | capability                          | permission | role           | contextlevel | reference |
      | local/coursedynamicrules:createrule | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    Then I should see "Draft ideas"
    And "Duplicate rule" "link" should not exist
