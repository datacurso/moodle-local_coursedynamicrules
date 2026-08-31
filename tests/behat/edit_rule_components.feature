@local @local_coursedynamicrules @local_coursedynamicrules_edit
Feature: Manage existing rule conditions and actions
  In order to keep a rule's components correct before the rule ever runs
  As a teacher
  I need existing conditions and actions of a never-activated rule to be editable in place and
  removable - the activation seal, not a blanket withholding, is what protects running rules

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher1  | User1    | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following local coursedynamicrules no course access rules exist:
      | course | active | periodvalue | periodunit | primaryroles | copyroles | subject           | body                  |
      | C1     | 0      | 1           | days       | student      |           | Original subject  | Original body content |

  # Bounded in-place editing (product directive 2026-08-31): the 1.8.1 withholding existed because
  # editing could change what an already-running rule did to learners; the activation seal
  # dissolved that risk - a never-activated rule cannot have run - so the pencil returns exactly
  # inside that bound. These scenarios pin the new contract on an UNSEALED rule: the pencil is
  # offered next to the trash can, and the editor opens PRELOADED and saves in place. The sealed
  # side of the bound (no pencil, URL refused) is pinned in rule_activation_lock.feature.
  Scenario: An editing teacher sees both the edit and delete controls on a draft's condition
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    When I click on "Edit conditions" "link"
    Then I should see "Users who take more than 1 days without accessing this course."
    And ".fa-trash" "css_element" should exist
    And ".fa-pencil" "css_element" should exist

  Scenario: Editing a draft's condition opens preloaded and saves in place
    Given I log in as "teacher1"
    When I visit the coursedynamicrules edit page for the latest condition in course "C1"
    # Preloaded: the stored period is already in the field.
    Then the field "periodvalue" matches value "1"
    When I set the field "periodvalue" to "3"
    And I press "Save changes"
    Then I should see "Users who take more than 3 days without accessing this course."
    And I should not see "Users who take more than 1 days without accessing this course."

  Scenario: Editing a draft's action opens preloaded and saves in place
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit actions" "link"
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
    # Preloaded: the stored subject is already in the field.
    Then the field "Subject" matches value "Original subject"
    When I set the field "Subject" to "Edited subject"
    And I press "Save changes"
    Then I should see "Send notification 'Edited subject' to users"
    And I should not see "Send notification 'Original subject' to users"

  # Since 1.9.0 the editing teacher holds deletecondition/deleteaction (RISK_DATALOSS, explicit
  # PROHIBITs respected by the upgrade): whoever may build rules may also unbuild them. These
  # delete-flow scenarios therefore run as the teacher - proving the grant works end to end.
  Scenario: Deleting a condition through the real confirmation page removes only that condition
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    And ".fa-trash" "css_element" should exist
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-trash')]]" "xpath_element"
    And I press "Delete"
    And I press "Continue"
    Then I should not see "Users who take more than 1 days without accessing this course."
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit actions" "link"
    And I should see "Send notification 'Original subject' to users"

  Scenario: Deleting an action through the real confirmation page removes only that action
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit actions" "link"
    And ".fa-trash" "css_element" should exist
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-trash')]]" "xpath_element"
    And I press "Delete"
    And I press "Continue"
    Then I should not see "Send notification 'Original subject' to users"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    And I should see "Users who take more than 1 days without accessing this course."
