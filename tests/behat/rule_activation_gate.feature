@local @local_coursedynamicrules @local_coursedynamicrules_gate
Feature: The activity a rule opens is closed by ACTIVATING the rule, never by configuring it
  In order to build a rule without taking anything away from my students while I work
  As a teacher
  I need the restriction that gates the reward to appear when I switch the rule on - not when I name it

  # The defect this pins (found 2026-09-10, present since release 1.1.1, 2024-12-02): the
  # enable-activity action wrote its gate - a user restriction holding an empty list of students,
  # combined by AND and set not to show itself - the moment the operator chose the activity. Since
  # Moodle requires every restriction to be satisfied and that list stays empty until a run fills
  # it, the activity was not merely locked but HIDDEN from every student as soon as the action was
  # saved: for a rule nobody had activated, that had never run, and that the listing correctly
  # reported as inactive.
  #
  # These scenarios exist because the PHPUnit tests prove the hook works and cannot prove the
  # ENDPOINT calls it. Activation is a sesskey-protected confirmation page; only a browser gets
  # there. Reading the line in editrule.php is not evidence that the line runs.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher1  | User1    | teacher1@example.com |
      | student1 | Student1  | User1    | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name        | idnumber |
      | assign   | C1     | Actividad A | acta     |
      | assign   | C1     | Actividad B | actb     |
    And the following local coursedynamicrules bare rules exist:
      | course | name      | active |
      | C1     | Gate rule | 0      |
    And the following local coursedynamicrules complete activity conditions exist:
      | course | rule      | activity |
      | C1     | Gate rule | acta     |
    And the following local coursedynamicrules enable activity actions exist:
      | course | rule      | activity |
      | C1     | Gate rule | actb     |

  @MDL-E2E-012
  Scenario: A rule nobody activated takes nothing away from the students
    # The rule is complete and names Actividad B as its reward - and has never been activated.
    Given I log in as "student1"
    When I am on "C1" course homepage
    Then I should see "Actividad A"
    And I should see "Actividad B"

  @MDL-E2E-012
  Scenario: Activating the rule is the moment the reward closes
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "//tr[contains(., 'Gate rule')]//a[contains(@href, 'editrule.php')]" "xpath_element"
    When I set the field "Active" to "1"
    And I press "Save changes"
    # The save holds the activation back and asks the one question first.
    And I press "Activate permanently"
    Then I should see "The rule was activated"
    # And only NOW is the reward closed - for a student who has not completed Actividad A. The gate
    # is set not to show itself, so the activity does not appear greyed out: it is simply not there.
    And I log out
    And I log in as "student1"
    And I am on "C1" course homepage
    And I should see "Actividad A"
    But I should not see "Actividad B"
