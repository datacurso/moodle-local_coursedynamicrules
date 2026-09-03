@local @local_coursedynamicrules @local_coursedynamicrules_names
Feature: Rule names are escaped and descriptions surface on hover
  In order to protect privileged users from a rule name that arrived in a restored backup
  As a teacher managing course rules
  I need the name shown as text on every screen, and the description reachable without opening the rule

  # These are the two page scripts no unit test can load. rules.php and deleterule.php are the
  # only places a rule name reaches HTML, and both of them escape it through
  # component_renderer::escaped_name(). The unit tests pin that helper; only these scenarios pin
  # that the pages actually call it and still render.

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

  Scenario: A script payload in a rule name renders as text on the rules list
    # The form types this field PARAM_TEXT, which strips tags, so the form is not the threat.
    # Course restore is: it writes the name with no cleaning at all. The step below inserts it
    # verbatim, which is exactly what a crafted .mbz produces.
    Given the following local coursedynamicrules bare rules exist:
      | course | name                                            | active |
      | C1     | <script>alert(document.cookie)</script>Refuerzo | 0      |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    When I navigate to "Smart Rules AI" in current page administration
    # The assertion that matters is the XPath, not the text. "I should see" cannot tell the two
    # cases apart here and was measured doing exactly that: strip_tags() removes the TAGS and keeps
    # their CONTENT, so "alert(document.cookie)Refuerzo" is the page text whether the name was
    # escaped or emitted raw. Written that way the scenario passed with escaped_name() gutted.
    #
    # What does differ is the DOM: emitted raw, the payload becomes a real <script> element.
    # Matching on its content rather than on the tag keeps Moodle's own scripts out of the way.
    Then "//script[contains(text(), 'alert(document.cookie)')]" "xpath_element" should not exist
    And I should see "alert(document.cookie)Refuerzo"

  Scenario: A script payload in a rule name renders as text on the delete confirmation page
    # The sibling that shipped unescaped while the listing was already fixed.
    # core_renderer::confirm() emits its message through html_writer::tag('p', ...) untouched, so
    # nothing downstream protects this page.
    Given the following local coursedynamicrules bare rules exist:
      | course | name                                            | active |
      | C1     | <script>alert(document.cookie)</script>Refuerzo | 0      |
    And I log in as "teacher1"
    When I visit the coursedynamicrules delete page for the latest rule in course "C1"
    # Same reading as the scenario above: the DOM is what separates escaped from raw.
    Then "//script[contains(text(), 'alert(document.cookie)')]" "xpath_element" should not exist
    And I should see "alert(document.cookie)Refuerzo"

  Scenario: A rule with a description carries it as a tooltip, one without carries none
    # Presence and absence both matter: an empty tooltip box on every description-less rule would
    # be worse than no tooltip at all, which is why the code only wraps when there is a
    # description. Anchored on the span the plugin itself emits, never on a theme class.
    Given the following local coursedynamicrules bare rules exist:
      | course | name            | active | description         |
      | C1     | Con descripcion | 0      | Refuerza fracciones |
      | C1     | Sin descripcion | 0      |                     |
    And I log in as "teacher1"
    And I am on "C1" course homepage
    When I navigate to "Smart Rules AI" in current page administration
    Then "span[title='Refuerza fracciones']" "css_element" should exist
    And I should see "Sin descripcion"
    And "span[title='']" "css_element" should not exist
