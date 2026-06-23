# ADR-0001: SMS Phase 1 Implementation Decisions

## Status

Accepted

## Context

Phase 1 added SMS OTP support to Piwigo's `two_factor` plugin as a local fork. The original design notes allowed several implementation options, especially around schema, config keys, and upgrade behavior.

## Decision

The Phase 1 implementation uses these decisions:

1. SMS is implemented inside the existing `two_factor` plugin as a third allowed method alongside `external_app` and `email`.
2. The verification phone number is stored on the existing `TF_TABLE` row for `method = 'sms'` using a new nullable `phone_number` column.
3. SMS admin configuration uses the keys `enabled`, `base_url`, `api_key`, `sender_text`, `code_ttl`, `resend_delay`, and `debug`.
4. A runtime schema guard lazily adds `TF_TABLE.phone_number` before SMS reads or writes if an upgraded install missed the normal plugin update path.
5. The plugin `Version` header remains aligned with the upstream published revision to avoid false incompatibility warnings in Piwigo administration.

## Consequences

Positive:

- The MVP shipped with minimal schema and UI changes.
- Existing plugin abstractions could be reused for setup, verification, deactivation, and lockout logic.
- The runtime schema guard prevents production fatals on upgraded installs that did not run the plugin migration path.
- Piwigo admin no longer shows a false compatibility warning caused by a local-only version suffix.

Tradeoffs:

- SMS verification metadata is not stored in a dedicated profile table yet.
- Provider trace fields such as `phone_verified_at`, `last_provider_batch_id`, and `last_provider_msg_id` are not persisted in Phase 1.
- The Phase 1 config shape differs from the original draft naming scheme.

## Follow-up

Phase 2 may still introduce:

- a PLG-facing helper API
- purpose-aware SMS helpers beyond setup/login
- dedicated SMS metadata storage if traceability requirements grow
