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

# Two Factor SMS `project_sheet.md` Extension: CPT Profile Phone Source

## Phase 2: Draw SMS Verification Phone From CPT Owner Profile

Status: implemented slice, with album-owner enforcement added.

### `Action`

Change the SMS setup flow so the phone number used for SMS verification is drawn from the owner profile data managed by the CPT plugin instead of being entered as an independent free-form value inside the Two Factor Authentication block.

This keeps the user experience simple for a non-technical audience:

```text
My Profile
= owner edits the public/contact phone number once

Two Factor Authentication
= owner only verifies that profile phone by SMS

Profile Liveness Guard
= later uses the verified SMS phone for weekly liveness checks
```

---

## Why This Change Exists

The current Phase 1 SMS implementation works, but it stores the SMS phone directly in the `two_factor` table after setup. That was useful for a first working MVP, but the portal now has a richer owner profile managed by CPT.

The contact phone is part of the public/profile identity and availability workflow, so it should be edited in the same place as the other profile fields:

```text
CPT My Profile
```

The Two Factor plugin should not become a second profile editor.

---

## Current CPT Source Data

CPT stores owner profile fields in a CPT-owned table:

```sql
piwigo_cpt_owner_profile
```

Relevant columns:

```text
root_album_id
owner_user_id
field_key
value_text
tag_id
updated_at
```

The contact-related field keys currently in scope are:

```text
contact_number
contact_phone
contact_sms
contact_whatsapp
```

Important interpretation:

```text
contact_number
= the actual phone number text

contact_phone
= controlled Yes/No flag: phone calls allowed/displayed

contact_sms
= controlled Yes/No flag: SMS allowed/displayed

contact_whatsapp
= controlled Yes/No flag: WhatsApp allowed/displayed
```

So the Two Factor plugin must not treat `contact_phone`, `contact_sms`, or `contact_whatsapp` as phone numbers. They are contact-channel flags. The real source number is `contact_number`.

---

## Design Decision

CPT is the canonical editor for the profile/contact phone.

Two Factor SMS is the verification authority.

```text
CPT contact_number
= proposed profile phone / contact source
= editable by the owner in My Profile

Two Factor sms phone_number
= verified SMS target
= stored only after successful OTP confirmation

PLG
= uses the verified Two Factor phone, not an unverified CPT value
```

This avoids silently trusting a newly edited public profile phone before it has been verified.

---

## Phone Source Precedence

### SMS setup screen

When SMS is not enabled yet:

```text
1. Read CPT contact_number for the current user/root owner profile.
2. Normalize it using Two Factor SMS phone normalization.
3. Show it in the SMS setup UI as the phone to verify.
4. If the number is missing or invalid, disable SMS setup and tell the owner to update My Profile first.
```

### SMS login challenge

When SMS is already enabled:

```text
Use the verified phone_number stored in the two_factor table.
```

Do not read a fresh CPT phone during login. Login must use the previously verified phone.

### CPT phone changed after SMS was enabled

When CPT `contact_number` differs from the verified Two Factor `phone_number`:

```text
1. Mark SMS verification as needing re-verification in the UI.
2. Do not silently overwrite the verified phone.
3. Do not send liveness/PLG SMS to the new CPT phone until OTP verification succeeds.
```

Implemented now:

```text
Show warning, keep login using the previously verified SMS phone,
and refresh the profile warning text after successful re-verification.
```

Better follow-up option:

```text
Add an explicit "Verify updated profile phone" flow without using deactivate/reactivate as the re-verification trigger.
```

---

## Album Owner 2FA Policy

An additional business rule is now enforced for non-admin album owners:

```text
- if a non-admin user owns albums, 2FA is required
- if they do not own albums anymore, their configured 2FA methods are removed automatically
- admins and webmasters are exempt from this policy
```

Implemented behavior:

```text
1. CPT ownership count is used as the policy input.
2. If the owner has albums but no configured 2FA method, the plugin redirects them to Profile until they set one up.
3. If the owner already has multiple methods, they may disable one method as long as at least one method remains enabled.
4. If they try to disable their last enabled method while still owning albums, the request is rejected in both UI and server-side WS handling.
5. If ownership drops to zero albums, the plugin removes the stored 2FA methods for that non-admin user on the next authenticated request.
```

---

## Recommended Configuration

Add SMS config flags:

```php
$conf['two_factor']['sms'] = array(
  'enabled' => false,
  'base_url' => 'https://api.smstools.sk',
  'api_key' => '',
  'sender_text' => '',
  'code_ttl' => 600,
  'resend_delay' => 60,
  'debug' => false,

  // Phase 2 CPT integration
  'use_cpt_profile_phone' => true,
  'allow_manual_sms_phone' => false,
  'profile_contact_field' => 'contact_number',
  'require_contact_sms_enabled' => false,
);
```

Field meaning:

```text
use_cpt_profile_phone
= read phone from CPT when preparing SMS setup

allow_manual_sms_phone
= fallback switch for local testing or emergency use

profile_contact_field
= defaults to contact_number

require_contact_sms_enabled
= optional stricter rule: setup SMS only if CPT contact_sms is Yes
```

Recommended production defaults:

```text
use_cpt_profile_phone = true
allow_manual_sms_phone = false
require_contact_sms_enabled = false for MVP
```

Reason: `contact_sms` is mostly a public contact preference. The verification phone can still be used for account safety even if public SMS contact is disabled. If the business rule later says "liveness by SMS requires SMS contact enabled", then switch `require_contact_sms_enabled` to true.

---

## CPT Read Helper Design

Add helper functions in `includes/functions.inc.php` or a small new file such as:

```text
includes/cpt_profile_phone.inc.php
```

Suggested functions:

```php
tf_cpt_profile_available(): bool
```

Returns true when CPT profile storage can be read.

```php
tf_get_cpt_owner_profile_contact_rows(int $user_id): array
```

Returns contact rows from CPT for the current owner/root album.

```php
tf_get_cpt_profile_contact_number(int $user_id): ?string
```

Returns the raw `contact_number` value from CPT.

```php
tf_get_cpt_profile_contact_flags(int $user_id): array
```

Returns parsed Yes/No flags for `contact_phone`, `contact_sms`, and `contact_whatsapp`.

```php
tf_get_sms_setup_phone_candidate(int $user_id): array
```

Returns a normalized, UI-ready phone source payload.

Suggested return shape:

```php
array(
  'available' => true,
  'raw_phone' => '+421 905 000 000',
  'normalized_phone' => '+421905000000',
  'source' => 'cpt_owner_profile.contact_number',
  'flags' => array(
    'contact_phone' => true,
    'contact_sms' => true,
    'contact_whatsapp' => false,
  ),
  'error' => null,
)
```

---

## SQL Access Pattern

Prefer using CPT constants/helpers when loaded:

```php
if (defined('CPT_OWNER_PROFILE_TABLE')) {
  $table = CPT_OWNER_PROFILE_TABLE;
}
```

Fallback table name when CPT is not bootstrapped:

```php
$table = $prefixeTable . 'cpt_owner_profile';
```

Preferred query when the CPT root-owner helper is available:

```sql
SELECT field_key, value_text, tag_id
FROM piwigo_cpt_owner_profile
WHERE owner_user_id = :user_id
  AND root_album_id = :root_album_id
  AND field_key IN ('contact_number', 'contact_phone', 'contact_sms', 'contact_whatsapp')
```

Fallback query when the root album cannot be resolved:

```sql
SELECT field_key, value_text, tag_id
FROM piwigo_cpt_owner_profile
WHERE owner_user_id = :user_id
  AND field_key IN ('contact_number', 'contact_phone', 'contact_sms', 'contact_whatsapp')
ORDER BY updated_at DESC
```

Important rule:

```text
Use contact_number as the phone number.
Use contact_phone/contact_sms/contact_whatsapp only as channel flags.
```

---

## Server-Side Setup Rule

`tf_setup_sms()` must not trust the phone number submitted by JavaScript when CPT-phone mode is enabled.

Recommended behavior:

```php
if ($conf['two_factor']['sms']['use_cpt_profile_phone']) {
  $candidate = tf_get_sms_setup_phone_candidate((int) $user['id']);

  if (empty($candidate['normalized_phone'])) {
    return new PwgError(401, l10n('Please add a valid contact phone number in My Profile first.'));
  }

  $phone_number = $candidate['normalized_phone'];

  if (!empty($params['phone_number'])) {
    $submitted = tf_normalize_phone_number($params['phone_number']);
    if ($submitted !== $phone_number) {
      return new PwgError(403, l10n('The submitted phone number does not match your profile phone number.'));
    }
  }
}
```

Manual phone entry should only be accepted when:

```text
use_cpt_profile_phone = false
```

or:

```text
allow_manual_sms_phone = true
```

---

## Profile UI Contract

Currently the SMS template has editable fields:

```text
Your phone number
Confirm your phone number
```

Phase 2 UX should become:

```text
Phone number from My Profile
Confirm this phone number
```

Implemented Phase 2 behavior:

```text
- The 2FA block no longer exposes manual phone-entry fields.
- The CPT contact phone is shown as a readonly source field.
- If no valid CPT phone exists, show a message: "Add your contact phone in My Profile first."
- The Send SMS button is disabled until a valid CPT phone is available.
- If the verified SMS phone differs from CPT, show both masked values and require re-verification.
```

Suggested Smarty variables:

```text
TF_SMS_PROFILE_PHONE_NUMBER
TF_SMS_PROFILE_PHONE_NORMALIZED
TF_SMS_PROFILE_PHONE_MASKED
TF_SMS_PROFILE_PHONE_AVAILABLE
TF_SMS_PROFILE_PHONE_SOURCE
TF_SMS_PROFILE_PHONE_ERROR
TF_SMS_VERIFIED_PHONE_NUMBER
TF_SMS_PHONE_NEEDS_REVERIFY
```

Existing variable compatibility:

```text
TF_SMS_PHONE_NUMBER
```

should remain available, but it should represent the verified Two Factor phone when SMS is already enabled.

---

## JavaScript Changes

Current `setupSms(code)` finalizes verification without re-entering a phone number.

Phase 2 behavior:

```text
- Start setup without accepting an owner-edited phone value.
- Re-read CPT server-side when sending the setup SMS.
- Finalize setup using only the OTP code.
- Refresh the stale-phone warning block in place after successful re-verification so the masked verified phone display is current without a page reload.
```

If `TF_SMS_PROFILE_PHONE_AVAILABLE` is false:

```text
- opening the SMS section shows a toaster error
- Send SMS button remains disabled
- owner is directed to My Profile
```

---

## Data Flow

```text
1. Owner edits contact_number in CPT My Profile.
2. Two Factor profile block reads CPT contact_number.
3. Owner opens SMS setup.
4. Two Factor displays CPT phone as readonly source.
5. Two Factor sends OTP to the CPT phone.
6. Owner enters OTP.
7. Two Factor saves normalized phone_number to TF_TABLE on method = sms.
8. The profile UI clears the stale-phone warning after successful re-verification.
9. PLG later uses verified TF_TABLE phone, not raw CPT phone.
```

---

## Security Rules

- Do not trust submitted `phone_number` when CPT-phone mode is enabled.
- Do not let anonymous visitors trigger SMS.
- Do not let one user verify another user's CPT phone.
- Do not use `contact_phone`, `contact_sms`, or `contact_whatsapp` as phone numbers.
- Do not send PLG/liveness SMS to an unverified CPT value.
- Do not silently overwrite the verified Two Factor phone when CPT profile phone changes.
- Do not allow a non-admin album owner to disable their last enabled 2FA method.
- Keep API key server-only.
- Log phone values only masked.
- Reject malformed phone values before calling SMSTOOLS.

---

## PHPUnit Test Plan

1. Reads `contact_number` from `piwigo_cpt_owner_profile` for the current owner.
2. Ignores `contact_phone`, `contact_sms`, and `contact_whatsapp` as phone-number sources.
3. Correctly parses contact-channel flags from controlled Yes/No fields.
4. Uses `root_album_id` when CPT root helper is available.
5. Falls back safely when CPT is installed but helper functions are unavailable.
6. Fails closed when CPT phone is missing and manual fallback is disabled.
7. Rejects a submitted phone that differs from the CPT phone.
8. Allows setup when submitted phone matches the CPT phone.
9. Keeps login SMS using the already verified `TF_TABLE.phone_number`.
10. Detects when CPT phone differs from verified SMS phone and marks re-verification needed.
11. Does not expose the full phone number in admin/status payloads.
12. Masks phone numbers in logs and UI diagnostics.

---

## Cypress / E2E Acceptance Scenarios

1. Owner sees SMS setup phone prefilled from CPT My Profile.
2. Owner cannot edit the source phone inside the 2FA block when CPT-phone mode is enabled.
3. Owner cannot manually replace the displayed CPT phone inside the 2FA block.
4. Owner without a CPT contact number sees a clear instruction to update My Profile first.
5. Owner changes CPT contact number and 2FA shows that SMS verification needs to be refreshed.
6. Owner completes SMS verification and the masked verified-phone display updates without a page reload.
7. Crafted request with a different phone number is rejected server-side.
8. Album owner cannot disable their last remaining 2FA method.
9. Album owner with no configured 2FA method is redirected to Profile setup after login.

---

## Definition of Done

- The SMS setup screen draws its phone number from CPT `contact_number`.
- Manual phone entry in the 2FA block is disabled by default.
- Server-side setup re-reads CPT and rejects mismatched submitted phone values.
- Successful SMS setup stores the normalized verified phone in the existing `two_factor.phone_number` column.
- PLG continues to use the verified Two Factor phone for liveness checks.
- CPT channel flags are interpreted as flags only, not as phone-number values.
- Missing or invalid CPT phone fails closed with a clear owner-facing message.
- Non-admin album owners must keep at least one 2FA method enabled while they own albums.
- Album-owner policy is enforced in both the profile UI and the deactivation WS handler.
- Tests cover successful setup, missing phone, mismatched phone, stale verified phone, flag interpretation, and album-owner policy helpers.
