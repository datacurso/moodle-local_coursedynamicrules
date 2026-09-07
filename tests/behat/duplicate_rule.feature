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
    And the following local coursedynamicrules bare rules exist:
      | course | name        | active | description   |
      | C1     | Draft ideas | 0      | Worth copying |

  Scenario: An open draft is duplicated into a second draft that keeps the source untouched
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    When I click on "Duplicate rule" "link"
    Then I should see "Rule duplicated"
    And I should see "Draft ideas (copy)"
    And I should see "Draft ideas"

  Scenario: Without the create capability the copy control is not offered
    Given the following "permission overrides" exist:
      | capability                          | permission | role           | contextlevel | reference |
      | local/coursedynamicrules:createrule | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    Then I should see "Draft ideas"
    And "Duplicate rule" "link" should not exist
