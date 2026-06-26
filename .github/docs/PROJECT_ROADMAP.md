# Two Factor SMS `PROJECT_ROADMAP.md` (Roadmap 🗺️)

## Project Vision

Adapt Piwigo's official Two Factor Authentication plugin for a non-technical audience by adding SMS OTP delivery through SMSTOOLS.sk while preserving the plugin's existing login-2FA architecture, token checks, retry limits, lockout behavior, and profile-page integration.

This customization is the SMS transport foundation for the later Profile Liveness Guard (PLG) plugin.

---

## Current Baseline

The upstream `piwigo-two_factor` plugin currently supports:

- authenticator-app TOTP (`external_app`)
- email OTP (`email`)
- profile-page setup block
- webservice setup/status/deactivate methods
- login interception after a correct username/password pair
- temporary session validation while a user still needs to complete 2FA
- max-attempt and lockout behavior

The plugin's current allowed methods are conceptually:

```php
external_app
email
```

The SMS customization adds:

```php
sms
```

---

## Phase 1: SMS OTP Transport MVP

### Goal

Allow a logged-in user to configure and use SMS-based OTP as a Two Factor Authentication method.

Status: implemented.

### Features

- **SMS Method Registration:** Add `sms` to the allowed 2FA methods.
- **SMSTOOLS Client:** Implement a small isolated API client for `https://api.smstools.sk/3/send_batch`.
- **SMS Setup Flow:** Owner verifies their phone number by receiving a six-digit SMS code and entering it in the profile/UCP flow.
- **SMS Login Flow:** After correct password login, users with enabled SMS 2FA receive/enter an SMS OTP.
- **Rate Limiting:** Reuse or mirror the email method's rate-limit pattern so users cannot request unlimited SMS messages.
- **Retry & Lockout:** Reuse existing max-attempt and lockout behavior.
- **Admin Config:** Add API key, sender text, SMS enabled flag, base URL, code TTL, resend delay, and optional debug mode.
- **Audit Logging:** Log SMS send attempts, accepted provider responses, failed provider responses, and verification outcomes without logging raw OTP codes.

Implementation notes:

- Phone numbers are stored on the existing `TF_TABLE` `sms` row via a `phone_number` column.
- Upgraded installs self-heal the `phone_number` column at runtime if the normal plugin update hook did not run.
- The plugin version header stays aligned with the upstream published revision to avoid false compatibility warnings in Piwigo administration.

Implemented extension after Phase 1:

- SMS setup now reads the candidate phone from CPT `contact_number` by default.
- Manual phone-entry fields were removed from the 2FA block.
- The profile UI shows masked verified-vs-current CPT phone warnings when re-verification is needed and refreshes that display after successful SMS verification.
- Non-admin album owners are redirected to profile setup when they own albums but have no configured 2FA method.
- Non-admin album owners cannot disable their last remaining 2FA method while they still own albums.
- Once a non-admin user no longer owns albums, the plugin removes their stored 2FA methods on a later authenticated request.

### Non-goals

- Weekly liveness guard scheduling.
- Album tree privacy enforcement.
- Public profile phone display.
- Visitor-triggered OTP.
- Paid SMS billing dashboard.

---

## Phase 2: PLG Integration Readiness

### Goal

Expose safe helper functions or webservice methods that the Profile Liveness Guard plugin can use to send verification OTPs without duplicating SMS provider logic.

### Features

- **Internal Send Helper:** `tf_sms_send_otp_to_user($user_id, $purpose, $context = array())`
- **Purpose-aware Messaging:** Support separate message templates for login, setup, and liveness verification.
- **Provider Response Storage:** Persist or return provider `batch_id` / `msg_id` to callers for traceability.
- **No Anonymous Sending:** Ensure helper refuses anonymous or visitor-triggered use.
- **Rate-limit by Purpose:** Liveness resend limits should not collide with login retry UX but must still prevent abuse.

Current gap:

- Phase 1 exposes `tf_send_sms_message(...)` internally and returns `batch_id` / `msg_id` from the provider call, but it does not yet provide the documented PLG-facing helper abstraction.

---

## Phase 3: Delivery Status / Received SMS Follow-up

### Goal

Optionally integrate delivery status checks and/or callbacks later.

### Features

- Poll or accept callback state updates for SMS delivery.
- Store message status for admin diagnostics.
- Optionally inspect received SMS replies if a virtual line is later enabled.

This is deferred; the OTP confirmation itself should not depend on delivery callbacks.

---

## Safety Position

This plugin must never let public visitors trigger SMS messages to profile owners. SMS can be sent only by:

- the logged-in account owner for their own setup/login flow,
- the PLG scheduled system task once Phase 2 is implemented.

Current implementation note:

- Phase 1 implements owner-driven setup and login SMS flows.
- PLG-triggered sends and any admin-triggered resend tooling are still roadmap items.

---

## Definition of Roadmap Done

- SMS OTP works for setup and login.
- SMSTOOLS API credentials are stored only in server-side Piwigo config.
- Rate limiting prevents SMS spam.
- Login remains secure if SMS provider is unavailable.
- PLG can call a documented helper without knowing SMSTOOLS details.

Current status:

- The first four bullets are satisfied by Phase 1.
- The PLG helper contract remains open work for Phase 2.
