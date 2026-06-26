# ADR-0002: CPT Phone Source and Album-Owner 2FA Policy

## Status

Accepted

## Context

After the SMS OTP MVP shipped, two follow-up requirements became concrete:

1. the phone number used for SMS setup should come from CPT owner-profile data instead of a free-form field inside the Two Factor block
2. non-admin album owners must keep 2FA enabled while they own at least one album that contains images

The implementation needed to preserve the existing verified-phone login behavior while preventing silent trust of newly edited profile phones.

## Decision

The implemented extension uses these decisions:

1. SMS setup reads the candidate phone from CPT `contact_number` by default.
2. Manual phone-entry fields are removed from the Two Factor profile block in the default flow.
3. The verified SMS phone remains stored on the `two_factor` `sms` row and continues to be the phone used during login.
4. If the CPT phone changes after SMS was enabled, the plugin shows a masked re-verification warning instead of silently replacing the verified phone.
5. After successful SMS re-verification, the profile UI updates the masked verified-phone display in place without requiring a page reload.
6. Non-admin album owners are treated as requiring 2FA only when CPT ownership detection reports at least one owned album that currently contains images.
7. Album owners with images and without any configured 2FA method are redirected to Profile setup after login.
8. Album owners with images may disable an individual method only if at least one other 2FA method remains enabled.
9. When a non-admin user no longer owns any album with images, the plugin removes their stored 2FA methods on a later authenticated request.

## Consequences

Positive:

- The 2FA block no longer acts as a second profile editor for phone data.
- Login and later PLG-style flows continue to rely only on verified phone numbers.
- Users receive explicit stale-phone feedback when CPT and verified SMS state diverge.
- The album-owner requirement is enforced in both client and server behavior.

Tradeoffs:

- The current re-verification UX still uses the normal SMS setup path rather than a dedicated "verify updated phone" flow.
- Automatic removal of stored 2FA methods when image-bearing ownership ends is policy-heavy and may need revisiting if requirements soften.
- PLG-facing helper abstractions remain separate follow-up work.

## Validation

The slice is covered by:

- plugin-local PHPUnit helper tests for phone-candidate, stale-phone, and album-owner policy behavior
- Cypress coverage for the readonly CPT-phone setup flow and the browser-level last-method owner guard
