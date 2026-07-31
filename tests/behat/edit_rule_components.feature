@local @local_coursedynamicrules @local_coursedynamicrules_edit
Feature: Edit existing rule conditions and actions
  In order to correct a rule's condition or action without losing its history
  As a teacher
  I need to edit an existing condition or action in place, with the stored values preloaded and
  exactly one row persisted, and have foreign or tampered component ids rejected

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
      | course | periodvalue | periodunit | primaryroles | copyroles | subject           | body                  |
      | C1     | 1           | days       | student      |           | Original subject  | Original body content |

  Scenario: Editing a condition preloads its stored values and updates it in place
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    And ".fa-pencil" "css_element" should exist
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
    Then the field "periodvalue" matches value "1"
    And I set the field "periodvalue" to "5"
    And I press "Save changes"
    And I should see "Users who take more than 5 days without accessing this course."
    And I should not see "Users who take more than 1 days without accessing this course."
    And "(//div[contains(@class, 'instance-card')])[2]" "xpath_element" should not exist

  Scenario: Editing an action preloads its stored values and updates it in place
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit actions" "link"
    And ".fa-pencil" "css_element" should exist
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
    Then the field "messagesubject" matches value "Original subject"
    And I set the field "messagesubject" to "Edited subject"
    And I press "Save changes"
    And I should see "Send notification 'Edited subject' to users"
    And I should not see "Send notification 'Original subject' to users"
    And "(//div[contains(@class, 'instance-card')])[2]" "xpath_element" should not exist

  Scenario: An invalid edit is rejected by the same validation used at creation
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
    And I set the field "periodvalue" to "0"
    And I press "Save changes"
    Then I should see "The period must be a whole number greater than 0."
    And I should see "Users who take more than 1 days without accessing this course."

  # The two "rejected before rendering/saving" ownership scenarios that used to live here were
  # removed: they asserted the fatal-error page text, but behat_hooks::i_look_for_exceptions() fails
  # any step that lands on a fatalerror page, so the pattern could never pass under Behat. That
  # coverage now lives in PHPUnit instead: see
  # tests/helper/ownership_test.php (ruleid/course mismatch rejection) and
  # tests/core/condition_test.php / tests/core/action_test.php (foreign/unscoped upsert rejection).

  # Deletion is manager-only by design: deletecondition and deleteaction carry RISK_DATALOSS and are
  # granted to the manager archetype alone, while createcondition/updatecondition (and their action
  # counterparts) are granted to editingteacher. That asymmetry is why in-place editing matters: a
  # teacher can create a component but cannot delete it, so before this feature a teacher had no way
  # at all to correct one. These two scenarios therefore run as an administrator.
  Scenario: Deleting a condition through the real confirmation page removes only that condition
    Given I log in as "admin"
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
    Given I log in as "admin"
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

  @javascript @gradeinactivity_edit
  Scenario: Editing a grade in activity condition preloads and updates the dynamic threshold region
    Given the following "activities" exist:
      | activity | name   | course | idnumber | completion | completionusegrade |
      | quiz     | Quiz 1 | C1     | quiz1    | 2          | 1                  |
    And the following local coursedynamicrules grade in activity rules exist:
      | course | activity | condition | value | subject                 |
      | C1     | quiz1    | gradegte  | 50    | Grade threshold subject |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
    Then I should see "Quiz - Quiz 1"
    And I toggle the "gradegte" threshold for "quiz1" to "75"
    And I press "Save changes"
    And I should see "Quiz - Quiz 1"
