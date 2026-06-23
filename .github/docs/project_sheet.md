# Two Factor SMS `project_sheet.md` (The Present 📜)

This document is the living technical specification for adding SMS OTP support to Piwigo's official `two_factor` plugin.

The customization should be implemented in a local fork or derivative plugin, not as a large unrelated patch inside CPT.

---

## Phase 1: SMSTOOLS-backed SMS OTP

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

Add or extend Piwigo `two_factor` config:

```php
$conf['two_factor']['sms'] = array(
  'enabled' => false,
  'provider' => 'smstools',
  'apikey' => '',
  'sender_text' => 'PIWIGO',
  'simple_text' => true,
  'code_ttl_seconds' => 600,
  'resend_delay_seconds' => 60,
  'setup_resend_delay_seconds' => 60,
  'login_resend_delay_seconds' => 60,
  'debug_log_provider_response' => false,
);
```

Security note: never expose `apikey` to JavaScript, templates, logs, or webservice responses.

---

## Data Model

Minimum addition to `two_factor` storage:

The existing `TF_TABLE` can store the method `sms` using the same method/secret model as email.

Suggested additional metadata table for phone verification:

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

```php
tf_sms_normalize_phone(string $raw): ?string
tf_sms_generate_code(): string
tf_sms_send_code(int $user_id, string $phone_e164, string $purpose): array|PwgError
tf_sms_store_session_code(string $code, string $purpose): void
tf_sms_verify_code(string $code, string $purpose): bool
tf_sms_rate_limit(string $purpose, int $now): true|int
```

---

## Webservice Methods

### `twofactor.setup.sms`

Step 1: owner submits phone, plugin sends setup OTP.

Parameters:

```text
phone
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
7. Phone is marked verified.

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
- OTP expires after `code_ttl_seconds`, default 600 seconds.
- Wrong codes count against existing max-attempts.
- Provider failure should fail closed: login remains incomplete, but no album privacy action is taken by this plugin.

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

1. Phone normalization accepts Slovak local and E.164 numbers.
2. Phone normalization rejects malformed values.
3. SMS payload builder creates valid SMSTOOLS JSON.
4. API key is never returned in public config/status.
5. Rate limit blocks immediate repeated send.
6. Correct OTP verifies within TTL.
7. OTP fails after TTL.
8. Wrong OTP decrements attempts.
9. Provider `id != OK` returns safe error.
10. Setup finalization enables `sms` only after correct code.
11. Deactivation removes SMS method and leaves other methods untouched.

---

## Cypress / E2E Acceptance Scenarios

1. Owner enables SMS 2FA from profile with valid phone and OTP.
2. Owner cannot enable SMS 2FA with invalid phone.
3. Owner logs in and completes SMS challenge.
4. Wrong SMS code shows an error and eventually triggers lockout.
5. Resend button is rate-limited.
6. Non-owner cannot trigger SMS for another user.
7. Admin can see whether SMS 2FA is enabled without seeing the full phone number.

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
