@local @local_coursedynamicrules @local_coursedynamicrules_aiaction
Feature: Reach the create AI activity action form without hitting a wall
  In order to build a rule that generates a reinforcement activity
  As a teacher
  I need the action form to open, and to be told about a missing dependency only when one is
  actually missing

  # Why this file exists, and why these assertions and not others.
  #
  # This release migrates the action away from local_coursegen\mod_manager, a class Course Creator
  # AI 2.0.3 removed without leaving an alias, and rewrites the required-plugin checks the form
  # performs before it lets anybody configure the action.
  #
  # Neither half was reachable from PHPUnit. The migration lives inside an execute() path that only
  # runs from cron against an external service, and the plugin checks run during form definition,
  # on a page - and a page is precisely what a unit test cannot load. The branch that broke this
  # release was found by a person opening a screen, not by a suite: 673 unit tests stayed green
  # through every commit that shipped it.
  #
  # So what is pinned here is the thing a unit test structurally cannot see: that the page renders,
  # that the form's own copy reaches the browser, and that the dependency warnings stay quiet when
  # every dependency is present. The generation itself is deliberately NOT exercised - it would put
  # a live call to an external AI service inside the test suite, which is slow, non-deterministic,
  # and bills somebody.

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
      | course | periodvalue | periodunit | primaryroles | copyroles | subject | body      |
      | C1     | 1           | days       | student      |           | Subject | Body text |

  @MDL-E2E-AI-001
  Scenario: The create AI activity action form opens and explains itself
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit actions" "link"
    When I click on "Create AI reinforcement activity" "link"
    Then I should see "This action will request the Datacurso AI service to generate a personalised reinforcement activity for users who meet the rule conditions."
    And I should see "Place before activity"

  @MDL-E2E-AI-002
  Scenario: With every required plugin installed the form raises no dependency warning
    # The form checks three plugins before letting anybody configure the action: availability_user,
    # local_coursegen and aiprovider_datacurso. The third was added in this release - the form used
    # to ignore it even though it supplies the HTTP client the action cannot run without - and the
    # second pointed its download link at a different plugin's page entirely.
    #
    # A wrong or missing entry is invisible while every dependency happens to be installed, which is
    # the state of any developer machine. The assertion below is therefore the negative one: on a
    # site where nothing is missing, the form must say nothing about missing plugins. If a future
    # edit points an entry at a component name that does not exist, this scenario goes red, because
    # get_plugin_info() returns nothing for it and the form starts warning about a plugin that is in
    # fact installed under its real name.
    Given I log in as "teacher1"
    And I am on "C1" course homepage
    And I navigate to "Smart Rules AI" in current page administration
    And I click on "Edit actions" "link"
    When I click on "Create AI reinforcement activity" "link"
    Then I should not see "to be installed and enabled"
    And I should not see "to be enabled"
