@local @local_coursedynamicrules @local_coursedynamicrules_edit
Feature: Manage existing rule conditions and actions
  In order to keep a rule's components correct without silently changing what a running rule does
  As a teacher
  I need existing conditions and actions to be removable, while editing them in place stays
  unavailable in this version

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
      | course | periodvalue | periodunit | primaryroles | copyroles | subject           | body                  |
      | C1     | 1           | days       | student      |           | Original subject  | Original body content |

  # In-place editing is implemented but withheld in this release: changing a component alters what
  # an already-running rule does to learners, and the advisory messages that must accompany that
  # are not written yet. These two scenarios pin that the control is gone from the listing, and the
  # third pins that the endpoint itself refuses the request - hiding the control alone would leave
  # a bookmarked link working.
  # The delete capability is manager-only by archetype (RISK_DATALOSS), so an editing teacher is
  # shown NO per-row controls at all: the pencil because in-place editing is withheld in this
  # release, and the trash because offering it invited a click that ended in a capability error
  # page - the control was offered to a role the endpoint refuses. Never offer what would be
  # refused. The admin scenarios below keep the positive half covered: a role holding delete*
  # still sees and uses the control.
  Scenario: An editing teacher sees no edit or delete control on a condition
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    When I click on "Edit conditions" "link"
    Then I should see "Users who take more than 1 days without accessing this course."
    And ".fa-trash" "css_element" should not exist
    And ".fa-pencil" "css_element" should not exist

  Scenario: An editing teacher sees no edit or delete control on an action
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    When I click on "Edit actions" "link"
    Then I should see "Send notification 'Original subject' to users"
    And ".fa-trash" "css_element" should not exist
    And ".fa-pencil" "css_element" should not exist

  Scenario: A direct link to the condition editor is refused and the condition is left untouched
    Given I log in as "teacher1"
    When I visit the coursedynamicrules edit page for the latest condition in course "C1"
    Then I should see "Editing an existing condition or action is not available in this version."
    And I should see "Users who take more than 1 days without accessing this course."
    And "periodvalue" "field" should not exist

  # Deletion is manager-only by design: deletecondition and deleteaction carry RISK_DATALOSS and are
  # granted to the manager archetype alone, while createcondition/createaction are granted to
  # editingteacher. These two scenarios therefore run as an administrator.
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
