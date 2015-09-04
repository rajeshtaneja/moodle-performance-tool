@_performance_testplan_generator
Feature: Warm up site for test plan.
  In order warm up test site
  As a student
  I need to visit course and it's activities.

  @javascript
  Scenario: Warm up test site
    Given I start capturing http requests
    And I log in as "s1"
    And I capture "login" http request and ensure "<div class=\"logininfo\">You are logged in as" exists on page
    And I should see "Test course"
    And I follow "Test course"
    And I capture "courseview" http request and ensure "Topic 1" exists on page
    And I follow "Test assignment name 1"
    And I capture "assignmentview" http request and ensure "Submission status" exists on page
    And I follow "TC"
    And I follow "Test book name 1"
    And I capture "bookview" http request and ensure "No content has been added to this book yet." exists on page
    And I follow "TC"
    And I follow "Test chat name 1"
    And I capture "chatview" http request and ensure "Test chat 1" exists on page
    And I follow "TC"
    And I follow "Test choice name 1"
    And I capture "choiceview" http request and ensure "Test choice 1" exists on page
    And I follow "TC"
    And I follow "Test database name 1"
    And I capture "dataview" http request and ensure "Test data 1" exists on page
    And I follow "TC"
    And I follow "Test folder name 1"
    And I capture "folderview" http request and ensure "Test folder 1" exists on page
    And I follow "TC"
    And I follow "Test forum name 1"
    And I capture "formview" http request and ensure "Test forum 1" exists on page
    And I follow "TC"
    And I follow "Test glossary name 1"
    And I capture "glossaryview" http request and ensure "Test glossary 1" exists on page
    And I follow "TC"
    And I log out
