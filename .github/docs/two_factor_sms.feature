# Filename: two_factor_sms.feature

Feature: SMS two-factor authentication
  As a non-technical gallery owner
  I want to receive a verification code by SMS
  So that I can secure my account without installing an authenticator app

  Background:
    Given the Two Factor SMS plugin is enabled
    And SMSTOOLS API credentials are configured
    And a registered user "gallery_owner" exists with password "password123"

  Scenario: Owner enables SMS two-factor authentication
    Given I am logged in as "gallery_owner"
    And I am on my profile page
    When I open the "Two Factor Authentication" section
    And I enter "+421905000000" as my verification phone number
    And I request an SMS setup code
    Then an SMS OTP should be sent to "+421905000000"
    And I should see a confirmation that the code was sent

    When I enter the correct SMS setup code
    Then SMS two-factor authentication should be enabled for "gallery_owner"

  Scenario: Owner cannot enable SMS 2FA with an invalid phone number
    Given I am logged in as "gallery_owner"
    And I am on my profile page
    When I open the "Two Factor Authentication" section
    And I enter "not-a-phone" as my verification phone number
    And I request an SMS setup code
    Then no SMS should be sent
    And I should see an invalid phone number message

  Scenario: Owner completes login with SMS code
    Given "gallery_owner" has enabled SMS two-factor authentication
    When I log in as "gallery_owner" with password "password123"
    Then I should be redirected to the two-factor verification page
    And an SMS OTP should be available for "gallery_owner"

    When I enter the correct SMS code
    Then I should be logged in as "gallery_owner"

  Scenario: Wrong SMS code is rejected
    Given "gallery_owner" has enabled SMS two-factor authentication
    When I log in as "gallery_owner" with password "password123"
    And I enter "000000" as the SMS code
    Then I should see that the code is invalid
    And I should remain on the two-factor verification page

  Scenario: SMS resend is rate-limited
    Given "gallery_owner" has enabled SMS two-factor authentication
    And I have just requested an SMS code
    When I request another SMS code immediately
    Then the request should be rejected
    And I should see how long to wait before requesting another code

  Scenario: User cannot send SMS to another user's phone
    Given another registered user "regular_visitor" exists with password "password123"
    And "gallery_owner" has SMS two-factor authentication configured
    And I am logged in as "regular_visitor"
    When I submit a crafted request to send an SMS code for "gallery_owner"
    Then the request should be rejected
    And no SMS should be sent to "gallery_owner"
