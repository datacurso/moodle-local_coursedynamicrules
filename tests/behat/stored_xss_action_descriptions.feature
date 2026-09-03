@local @local_coursedynamicrules
Feature: Configurable action descriptions are escaped on the delete confirmation page
  In order to protect privileged users from stored XSS
  As a manager reviewing course rules
  I need action descriptions to be escaped when I confirm their deletion

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |

  Scenario: A malicious prompt is shown escaped on the delete action page
    # A draft rule: the delete page only exists for unsealed rules, so the escape check must
    # run against the one state where anyone can actually reach this page.
    Given the following local coursedynamicrules AI activity actions exist:
      | course | active | prompt                                            |
      | C1     | 0      | &lt;script&gt;alert(document.cookie)&lt;/script&gt; |
    And I log in as "admin"
    When I visit the coursedynamicrules delete page for the latest action in course "C1"
    # When the description is escaped the payload renders as visible text; when it is injected
    # raw it becomes a live <script> element and is no longer visible text, so this fails.
    Then I should see "<script>alert(document.cookie)</script>"
