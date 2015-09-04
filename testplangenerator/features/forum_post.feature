@_performance_testplan_generator
Feature: As a student I post in forum
  In order to post in forum
  As a student
  I need to visit forum and post in it.

  @javascript
  Scenario: Forum post
    Given I start capturing http requests
    And I login as any "student" enrolled in course "TC"
    And I capture "login" http request and ensure "<div class=\"logininfo\">You are logged in as" exists on page
    And I should see "Test course"
    And I follow "Test course"
    And I follow "Test forum name 1"
    And I press "Add a new discussion topic"
    And I set the following fields to these values:
      | Subject | Post with attachment |
      | Message | This is the discussion by student |
    And I capture "fillDiscussionForm" http request and ensure "Test forum 1" exists on page with following globals from page:
      | SESSKEY                      | name="sesskey"\stype="hidden"\svalue="([^"]+)"         |
      | SESSION_FORUMFORMITEMID      | type="hidden"\sname="message\[itemid\]"\svalue="(\d+)" |
      | SESSION_FORUMFORMATTACHMENTS | value="(\d+)"\sname="attachments"\stype="hidden"       |
      | SESSION_USERID               | name="userid"\stype="hidden"\svalue="(\d+)"            |
    And I press "Post to forum"
    And I wait to be redirected
    And I capture "addDiscussion" http request and ensure "This page should automatically redirect" exists on page
    And I follow "TC"
    And I log out
    And I add listener to threadgroup
