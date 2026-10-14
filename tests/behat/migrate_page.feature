@quizaccess @quizaccess_duedate
Feature: Migrate legacy quiz due dates from the admin page
  In order to move to Moodle 5.3 without terminal access to the server
  As an admin
  I need to preview and run the due date migration from Site administration

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Hebrew   | BHB       |
    And the following "activities" exist:
      | activity | name        | course |
      | quiz     | Alephbet    | BHB    |
    And the following quizaccess_duedate legacy settings exist:
      | quiz     | duedate | penalty | cap |
      | Alephbet | +2 days | 5       | 50  |

  Scenario: Preview the migration and keep a late penalty
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > Quiz > Due date migration" in site administration
    Then I should see "Still to migrate"
    And I press "Preview"
    And I should see "Preview: nothing was written"
    And I should see "Alephbet"
    And I set the field "Course IDs, separated by commas" to the id of course "BHB"
    And I press "Write penalty rules"
    And I should see "10 rules for 5% per day, cap 50%"

  Scenario: Migrate the due dates into core
    Given the site has core quiz due dates
    And I log in as "admin"
    And I navigate to "Plugins > Activity modules > Quiz > Due date migration" in site administration
    When I press "Migrate due dates"
    Then I should see "Done"
    And I should see "Alephbet"
    And I should see "0" in the "Still to migrate" "table_row"
    And I am on the "Alephbet" "quiz activity" page
    And I should see "Due:"
