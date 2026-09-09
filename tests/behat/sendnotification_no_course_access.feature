@local @local_coursedynamicrules
Feature: No course access with send notification action
  In order to notify course users reliably
  As an admin
  I need no course access rules to send primary and copy notifications correctly

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student1  | User1    | student1@example.com |
      | student2 | Student2  | User2    | student2@example.com |
      | teacher1 | Teacher1  | User1    | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
      | Course 2 | C2        | 0        |
      | Course 3 | C3        | 0        |
      | Course 4 | C4        | 0        |
      | Course 5 | C5        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
      | student1 | C2     | student        |
      | teacher1 | C2     | editingteacher |
      | student1 | C3     | student        |
      | teacher1 | C3     | editingteacher |
      | student1 | C4     | student        |
      | teacher1 | C4     | editingteacher |
      | student1 | C5     | student        |
      | student2 | C5     | student        |
      | teacher1 | C5     | editingteacher |
    And the following local coursedynamicrules notifications are deleted:
      | username |
      | student1 |
      | student2 |
      | teacher1 |

  @MDL-INT-007
  Scenario: Inactive primary recipients notify both primary and copy users
    Given the following local coursedynamicrules no course access rules exist:
      | course | periodvalue | periodunit | primaryroles | copyroles      | subject                | body                                                           |
      | C1     | 1           | days       | student      | editingteacher | Nactivity notification  | {$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}     |
    And the following users last accessed courses:
      | username | course | secondsago |
      | student1 | C1     | 172800     |
      | teacher1 | C1     | 60         |
    When I run the scheduled task "\local_coursedynamicrules\task\no_course_access_task"
    Then "student1" should have 1 local coursedynamicrules notifications
    And "teacher1" should have 1 local coursedynamicrules notifications
    And the latest local coursedynamicrules notification for "student1" in "subject" should be exactly:
      """
      Nactivity notification
      """
    And the latest local coursedynamicrules notification for "student1" in "fullmessagehtml" should be exactly:
      """
      Student1 User1 - Student1 - User1
      """
    And the latest local coursedynamicrules notification for "teacher1" in "subject" should be exactly:
      """
      Observation: Student1 User1 received "Nactivity notification"
      """
    And the latest local coursedynamicrules notification for "teacher1" in "fullmessagehtml" should be exactly:
      """
      <p>Observation copy: this notification was generated for Student1 User1 based on the rule conditions.</p>Student1 User1 - Student1 - User1
      """

  @MDL-INT-007
  Scenario: Recently active primary recipients should not be notified
    Given the following local coursedynamicrules no course access rules exist:
      | course | periodvalue | periodunit | primaryroles | copyroles      | subject                | body                                                           |
      | C2     | 1           | days       | student      | editingteacher | Nactivity notification  | {$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}     |
    And the following users last accessed courses:
      | username | course | secondsago |
      | student1 | C2     | 60         |
      | teacher1 | C2     | 60         |
    When I run the scheduled task "\local_coursedynamicrules\task\no_course_access_task"
    Then "student1" should have 0 local coursedynamicrules notifications
    And "teacher1" should have 0 local coursedynamicrules notifications

  @MDL-INT-007
  Scenario: Users outside primary recipients do not trigger notifications
    Given the following local coursedynamicrules no course access rules exist:
      | course | periodvalue | periodunit | primaryroles   | copyroles | subject                | body                                                           |
      | C3     | 1           | days       | editingteacher | student   | Nactivity notification  | {$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}     |
    And the following users last accessed courses:
      | username | course | secondsago |
      | student1 | C3     | 172800     |
      | teacher1 | C3     | 60         |
    When I run the scheduled task "\local_coursedynamicrules\task\no_course_access_task"
    Then "student1" should have 0 local coursedynamicrules notifications
    And "teacher1" should have 0 local coursedynamicrules notifications

  @MDL-INT-007
  Scenario: Primary recipients can be notified without copy recipients
    Given the following local coursedynamicrules no course access rules exist:
      | course | periodvalue | periodunit | primaryroles | copyroles | subject                | body                                                           |
      | C4     | 1           | days       | student      |           | Nactivity notification  | {$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}     |
    And the following users last accessed courses:
      | username | course | secondsago |
      | student1 | C4     | 172800     |
      | teacher1 | C4     | 60         |
    When I run the scheduled task "\local_coursedynamicrules\task\no_course_access_task"
    Then "student1" should have 1 local coursedynamicrules notifications
    And "teacher1" should have 0 local coursedynamicrules notifications
    And the latest local coursedynamicrules notification for "student1" in "subject" should be exactly:
      """
      Nactivity notification
      """
    And the latest local coursedynamicrules notification for "student1" in "fullmessagehtml" should be exactly:
      """
      Student1 User1 - Student1 - User1
      """

  @MDL-INT-007
  Scenario: Only inactive users among primary recipients are notified
    Given the following local coursedynamicrules no course access rules exist:
      | course | periodvalue | periodunit | primaryroles | copyroles      | subject                | body                                                           |
      | C5     | 1           | days       | student      | editingteacher | Nactivity notification  | {$a-&gt;fullname} - {$a-&gt;firstname} - {$a-&gt;lastname}     |
    And the following users last accessed courses:
      | username | course | secondsago |
      | student1 | C5     | 172800     |
      | student2 | C5     | 60         |
      | teacher1 | C5     | 60         |
    When I run the scheduled task "\local_coursedynamicrules\task\no_course_access_task"
    Then "student1" should have 1 local coursedynamicrules notifications
    And "student2" should have 0 local coursedynamicrules notifications
    And "teacher1" should have 1 local coursedynamicrules notifications
    And the latest local coursedynamicrules notification for "student1" in "subject" should be exactly:
      """
      Nactivity notification
      """
    And the latest local coursedynamicrules notification for "student1" in "fullmessagehtml" should be exactly:
      """
      Student1 User1 - Student1 - User1
      """
    And the latest local coursedynamicrules notification for "teacher1" in "subject" should be exactly:
      """
      Observation: Student1 User1 received "Nactivity notification"
      """
    And the latest local coursedynamicrules notification for "teacher1" in "fullmessagehtml" should be exactly:
      """
      <p>Observation copy: this notification was generated for Student1 User1 based on the rule conditions.</p>Student1 User1 - Student1 - User1
      """
