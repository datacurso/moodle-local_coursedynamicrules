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

  # TODO(pending manual task 4.5 - npx grunt amd --component=local_coursedynamicrules):
  # this scenario exercises amd/src/grade_in_activity_form.js, whose compiled amd/build/*.min.js is
  # stale because building AMD is a manual/CI step, out of agent scope per the standing never-build
  # rule. It is commented out (not tag-negated) so it cannot accidentally run in CI before the AMD
  # build has landed. Uncomment once the build has run and the compiled JS is up to date.
  #
  # @javascript @gradeinactivity_edit
  # Scenario: Editing a grade in activity condition preloads and updates the dynamic threshold region
  #   Given the following "activities" exist:
  #     | activity | name   | course | idnumber | completion | completionusegrade |
  #     | quiz     | Quiz 1 | C1     | quiz1    | 2          | 1                  |
  #   And the following local coursedynamicrules grade in activity rules exist:
  #     | course | activity | condition | value | subject                 |
  #     | C1     | quiz1    | gradegte  | 50    | Grade threshold subject |
  #   And I log in as "teacher1"
  #   And I am on "C1" course homepage
  #   And I navigate to "Smart Rules AI" in current page administration
  #   And I click on "Edit conditions" "link"
  #   When I click on "(//div[contains(@class, 'instance-card')])[1]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
  #   Then I should see "Quiz - Quiz 1"
  #   And I toggle the "gradegte" threshold for "quiz1" to "75"
  #   And I press "Save changes"
  #   And I should see "Quiz - Quiz 1"
