# Two Factor SMS `project_sheet.md` (Implemented Phase 1 📜)

This document records the implemented Phase 1 SMS OTP customization for Piwigo's official `two_factor` plugin.

The customization should be implemented in a local fork or derivative plugin, not as a large unrelated patch inside CPT.

---

## Phase 1: SMSTOOLS-backed SMS OTP

Status: complete for MVP use.

### `Action`

Add an SMS method to the existing Two Factor Authentication plugin so non-technical users can receive a six-digit OTP by SMS instead of installing an authenticator app or relying on email.

---

## Source Plugin Context

The upstream plugin already provides:

- plugin constants and session keys in `main.inc.php`
- profile-page block injection through `load_profile_in_template`
- webservice registration through `ws_add_methods`
- login interception through `try_log_user`
- session-based "2FA not yet validated" state
- email OTP setup/send/verify behavior
- retry and lockout behavior through `max_attempts` and `lockout_duration`

The SMS method should mirror the email method where practical.

---

## SMSTOOLS API Context

SMSTOOLS sends SMS through HTTPS JSON calls.

Endpoint:

```text
POST https://api.smstools.sk/3/send_batch
```

Payload shape:

```json
{
  "auth": {
    "apikey": "API-KEY"
  },
  "data": {
    "message": "Your verification code is 123456",
    "simple_text": true,
    "sender": {
      "text": "PIWIGO"
    },
    "recipients": [
      {
        "phonenr": "+421905000000"
      }
    ]
  }
}
```

Expected successful response includes `id: OK` and provider ids such as `batch_id` and accepted recipient `msg_id`.

---

## Design Decision

Use SMS as another 2FA method, not as a replacement for all existing methods.

```text
external_app  = existing
email         = existing
sms           = new
```

Responsibilities:

```text
Two Factor SMS
= OTP generation, SMS sending, setup/login verification, retry limits, provider diagnostics

Profile Liveness Guard
= weekly liveness state machine and expiry workflow

CPT
= album-tree privatization when PLG says a profile expired
```

---

## Configuration

The implemented Piwigo `two_factor` SMS config is:

```php
$conf['two_factor']['sms'] = array(
  'enabled' => false,
  'base_url' => 'https://api.smstools.sk',
  'api_key' => '',
  'sender_text' => '',
  'code_ttl' => 600,
  'resend_delay' => 60,
  'debug' => false,
);
```

Notes:

- `base_url` was added so the API host is configurable without code edits.
- `sender_text` is validated to SMSTOOLS' 11-character limit.
- `api_key` remains server-side only and is not exposed to frontend JavaScript or status payloads.
- The implementation currently uses one resend delay value for setup and login flows.

---

## Data Model

The MVP stores SMS state in the existing `TF_TABLE`.

Implemented addition:

```sql
ALTER TABLE piwigo_two_factor
  ADD COLUMN phone_number VARCHAR(32) DEFAULT NULL AFTER method;
```

Stored in `TF_TABLE` per `(user_id, method)` row:

- `method = 'sms'`
- `secret`
- `phone_number`
- `enabled_at`

Not implemented in Phase 1:

- separate `piwigo_two_factor_sms_profile` table
- persisted `phone_verified_at`
- persisted `last_provider_batch_id`
- persisted `last_provider_msg_id`

The important rule remains that this verification phone is not treated as a public profile phone field.

Operational note:

- upgraded installs that missed the normal plugin update path lazily add `phone_number` at runtime before SMS reads/writes, to avoid fatal errors during rollout.

Original design sketch retained for future expansion:

```sql
CREATE TABLE piwigo_two_factor_sms_profile (
  user_id INT NOT NULL PRIMARY KEY,
  phone_e164 VARCHAR(32) NOT NULL,
  phone_verified_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  last_sms_sent_at DATETIME NULL,
  last_provider_batch_id VARCHAR(64) NULL,
  last_provider_msg_id VARCHAR(64) NULL
);
```

The important rule is to keep the verification phone separate from public profile contact display.

---

## Phone Number Model

Separate these concepts:

```text
verification phone
= used for OTP and weekly liveness
= owner/admin-visible only

public contact phone
= optional public profile field
= may be the same number but should not be the OTP source by default
```

Validation:

- store phone in E.164 format where possible, e.g. `+421905000000`
- accept Slovak local mobile input only if normalized server-side
- reject empty or malformed phone for SMS setup
- show only masked phone in normal UI, e.g. `+421905***000`

---

## Main Functions

Implemented helpers and control points are:

```php
tf_normalize_phone_number($phone_number): string|false
tf_get_sms_phone_owner($phone_number, $exclude_user_id = null): ?int
tf_mask_phone_number($phone_number): string
tf_generate_sms_message($code, $setup = false): string
tf_send_sms_message($phone_number, $code, $setup = false, $user_id = null): array
tf_get_sms_code_ttl(): int
tf_get_sms_resend_delay(): int
tf_rate_limit($time, $session_key, $window): true|int
```

Method-specific behavior is centered in `PwgTwoFactor` via:

- `setup()`
- `finaliseSetup()`
- `saveSecret()`
- `verifyCode()`
- `generateCode()`

---

## Webservice Methods

### `twofactor.setup.sms`

Step 1: owner submits phone, plugin sends setup OTP.

Parameters:

```text
phone_number
pwg_token
```

Step 2: owner submits OTP, plugin finalizes SMS method.

Parameters:

```text
code
pwg_token
```

### `twofactor.sendSms`

Used during login/verification screen to resend a code.

Parameters:

```text
pwg_token
```

---

## Login Flow

1. User enters username/password.
2. Piwigo authenticates credentials.
3. Two Factor plugin sees SMS method enabled for user.
4. Session gets `TF_SESSION_VALIDATED = false`.
5. Plugin redirects to `identification.php?tf`.
6. SMS code is sent or user clicks "send SMS code".
7. User enters six-digit code.
8. On success, plugin clears lockout and completes login.
9. On repeated failure, existing lockout rules apply.

---

## Setup Flow

1. Logged-in owner opens profile/UCP.
2. Owner opens Two Factor Authentication.
3. Owner enters verification phone number.
4. Plugin sends SMS setup code.
5. Owner enters code.
6. Plugin enables method `sms`.
7. Phone number is saved on the `sms` method row in `TF_TABLE`.

---

## Security Rules

- Never let anonymous visitors trigger SMS.
- Never let one user send SMS to another user's phone.
- Do not use public contact phone as the implicit OTP target.
- Enforce CSRF token on all setup/send/deactivate methods.
- Rate-limit by user and purpose.
- Do not log OTP codes.
- Do not expose API key.
- Do not save provider response bodies containing sensitive data unless debug is explicitly enabled.
- OTP expires after `code_ttl`, default 600 seconds.
- Wrong codes count against existing max-attempts.
- Provider failure should fail closed: login remains incomplete, but no album privacy action is taken by this plugin.

---

## Implementation Status

Implemented in Phase 1 MVP:

- `sms` is registered as a valid 2FA method.
- Admin settings support SMS enablement, base URL, API key, sender text, code TTL, resend delay, and debug mode.
- Profile/UCP setup flow sends and verifies SMS codes.
- Login flow supports SMS verification and resend.
- Phone numbers are normalized server-side and protected against cross-user reuse.
- Provider responses are handled safely and optionally logged in debug mode.
- Admin user list shows SMS enablement state without exposing the phone number.

Still open after Phase 1 MVP:

- separate SMS profile metadata storage
- reusable PLG-facing helper API

Implemented test coverage so far:

- plugin-local PHPUnit harness covers SMS helper behavior for normalization, masking, config accessors, message generation, rate limiting, fail-closed config handling, and config normalization.
- Cypress UI coverage exists in the parent Piwigo test harness for profile-page SMS setup validation and successful progression to code entry.
- broader end-to-end coverage for live login verification, resend, lockout, and provider-integrated flows is still pending.

---

## Accessibility Notes

- Use native form controls.
- Clearly label the phone input and code input.
- Explain that SMS may take a short time.
- Display masked phone number on verification screens.
- Do not rely on icons only.
- Use text feedback for "code sent", "invalid code", "wait before resend".

---

## PHPUnit Test Plan

Implemented now:

1. Phone normalization accepts formatted E.164 and `00`-prefixed inputs.
2. Phone normalization rejects malformed values.
3. SMS config accessors enforce expected bounds.
4. SMS message generation reflects purpose, code, gallery title, and TTL.
5. Rate limiting reports remaining wait time and resets after the window.
6. Incomplete SMS configuration fails closed.
7. Config normalization restores SMS defaults.

Still pending:

8. API key is never returned in public config/status.
9. Correct OTP verifies within TTL.
10. OTP fails after TTL.
11. Wrong OTP decrements attempts.
12. Provider `id != OK` returns safe error.
13. Setup finalization enables `sms` only after correct code.
14. Deactivation removes SMS method and leaves other methods untouched.

---

## Cypress / E2E Acceptance Scenarios

Implemented now in the parent Piwigo Cypress harness:

1. Owner sees a validation error when SMS phone confirmation does not match.
2. Owner advances from SMS setup send to code entry after a successful webservice response.

Still pending:

3. Owner enables SMS 2FA from profile with valid phone and OTP against the real backend flow.
4. Owner cannot enable SMS 2FA with an invalid phone number via live backend validation.
5. Owner logs in and completes SMS challenge.
6. Wrong SMS code shows an error and eventually triggers lockout.
7. Resend button is rate-limited.
8. Non-owner cannot trigger SMS for another user.
9. Admin can see whether SMS 2FA is enabled without seeing the full phone number.

---

## Definition of Done

- `sms` is registered as a valid 2FA method.
- SMSTOOLS client can send one OTP message.
- Setup flow verifies phone before enabling SMS 2FA.
- Login flow accepts SMS OTP and preserves existing lockout behavior.
- Rate limiting prevents spam.
- Provider errors are handled safely.
- The API key remains server-only.
- Unit and browser tests cover setup, send, verify, and rate limit paths.

Phase 1 MVP outcome:

- All runtime MVP features are implemented.
- Initial automated PHPUnit and Cypress coverage is now in place.
- Full test-plan completion remains pending for login, lockout, resend, deactivation, and provider-integrated scenarios.
