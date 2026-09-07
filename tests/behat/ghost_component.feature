@local @local_coursedynamicrules @local_coursedynamicrules_ghost
Feature: A condition whose activity was deleted stays visible and removable
  In order to keep a rule honest after its course changes
  As a teacher
  I need a condition that points at a deleted activity to show up with a warning and a way to remove it,
  instead of vanishing while it silently keeps the rule from ever firing

  # MDL-INT-014. A component is rendered only while it has a description, and a condition whose
  # course module was deleted used to describe itself as ''. The card disappeared from the
  # conditions page and from the rules list, the row stayed in the database, the condition
  # evaluated false forever, and the operator had no trash can to reach it with. These scenarios
  # walk the real pages: the ghost is listed with its warning, it keeps its trash can, its edit form
  # preselects nothing (a browser fills an unmarked <select> with the first option, which used to
  # re-point the rule on Save), and the confirmation page removes it.

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
    And the following "activities" exist:
      | activity | course | name          | idnumber | completion |
      | assign   | C1     | Doomed assign | DOOMED   | 1          |
      | assign   | C1     | Living assign | LIVING   | 1          |
    And the following local coursedynamicrules bare rules exist:
      | course | name             | active |
      | C1     | Rule with ghosts | 0      |
    And the following local coursedynamicrules complete activity conditions exist:
      | course | rule             | activity |
      | C1     | Rule with ghosts | DOOMED   |
      | C1     | Rule with ghosts | LIVING   |
    And the activity with idnumber "DOOMED" in course "C1" is deleted
    And I log in as "teacher1"
    And I am on "C1" course homepage

  @MDL-INT-014
  Scenario: The ghost is listed with its warning on the rules list and on the conditions page, trash can included
    When I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    Then I should see "no longer available in this course"
    And I should see "Living assign"
    And "//div[contains(@class, 'instance-card')][contains(., 'no longer available')]//i[contains(@class, 'fa-trash')]" "xpath_element" should exist
    And "//div[contains(@class, 'instance-card')][contains(., 'Living assign')]//i[contains(@class, 'fa-trash')]" "xpath_element" should exist

  @MDL-INT-014
  Scenario: The rules list itself shows the ghost's warning
    When I navigate to "Smart Rules AI" in current page administration
    Then I should see "no longer available in this course"
    And I should see "Living assign"

  @MDL-INT-014
  Scenario: Editing the ghost preselects no activity and refuses to save without one
    Given I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    When I click on "//div[contains(@class, 'instance-card')][contains(., 'no longer available')]//a[.//i[contains(@class, 'fa-pencil')]]" "xpath_element"
    And I press "Save changes"
    Then I should see "You must select an activity from this course."
    And the field "coursemodule" matches value ""

  @MDL-INT-014
  Scenario: Deleting the ghost through the real confirmation page removes only the ghost
    Given I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit conditions" "link"
    When I click on "//div[contains(@class, 'instance-card')][contains(., 'no longer available')]//a[.//i[contains(@class, 'fa-trash')]]" "xpath_element"
    And I press "Delete"
    And I press "Continue"
    Then I should not see "no longer available in this course"
    And I should see "Living assign"
