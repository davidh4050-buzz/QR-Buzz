# QR Buzz v0.9.7 Platform Administration

v0.9.7 introduces a dedicated operations environment at `/platform-admin/*`.

## Access

Platform administration requires the `qrbuzz_manage_platform` capability. QR Buzz creates a `qrbuzz_platform_admin` role and grants the capability to WordPress administrators on activation.

Grant access by assigning the QR Buzz Platform Admin role or adding the capability to a trusted user. Revoke access by removing the role or capability.

Customer workspace permissions do not grant platform administration access.

## Routes

- `/platform-admin/dashboard`
- `/platform-admin/users`
- `/platform-admin/workspaces`
- `/platform-admin/subscriptions`
- `/platform-admin/qrs`
- `/platform-admin/campaigns`
- `/platform-admin/product-analytics`
- `/platform-admin/system-health`
- `/platform-admin/diagnostics`
- `/platform-admin/webhooks`
- `/platform-admin/errors`
- `/platform-admin/audit-log`
- `/platform-admin/feature-flags`
- `/platform-admin/settings`

## Data Tables

v0.9.7 adds:

- `qrbuzz_audit_events`
- `qrbuzz_platform_events`
- `qrbuzz_application_errors`
- `qrbuzz_webhook_logs`
- `qrbuzz_feature_flags`
- `qrbuzz_diagnostic_snapshots`

These tables are installed with `dbDelta` and are safe for upgrades from v0.9.6.

## Support Workflow

Use the dashboard to answer four questions:

- What is happening today?
- Is anything broken?
- Does anyone need help?
- What are people enjoying using?

Recommended support flow:

1. Check Attention Required on `/platform-admin/dashboard`.
2. Open System Health for configuration or environment problems.
3. Use Users or Workspaces to inspect the affected account.
4. Generate a sanitised diagnostics snapshot when a support case needs a shareable summary.
5. Use Audit Log to confirm what actions were taken.

## Safe Actions

v0.9.7 supports:

- resend verification email
- reset onboarding
- suspend/reactivate workspace
- assign a local test plan
- mark errors resolved/ignored/investigating
- mark failed webhooks for retry review
- toggle feature flags
- create diagnostics snapshots

Sensitive actions require nonce protection and confirmation where appropriate. Actions are audited.

## Webhooks

Stripe webhook logs store event ID, event type, status, workspace reference where available, timestamps, retry count, and a sanitised error summary. QR Buzz does not store complete raw webhook payloads in the webhook log.

## Error Logging

The application error table groups QR Buzz-related fatal errors by fingerprint. List views avoid raw paths and secrets. Error context is redacted before storage.

## Feature Flags

Feature flags are operational toggles for experiments and must not replace plan entitlements. Undefined flags fail safely as disabled.

Seeded global flags:

- `new_dashboard`
- `library_grid_view`
- `compact_sidebar`
- `enhanced_onboarding`
- `qr_templates`
- `dark_mode`
- `advanced_insights`
- `purpose_driven_creation`

## Platform Events

Initial product events include:

- `user_registered`
- `workspace_created`
- `onboarding_completed`
- `qr_created`
- `qr_updated`
- `qr_downloaded`
- `campaign_created`
- `campaign_activated`
- `subscription_started`
- `subscription_changed`

Events are used for product analytics, recent activity, audit context, and the "What's happening today?" card.

## Retention

Default retention settings are editable in `/platform-admin/settings`:

- errors: 90 days
- audit events: 365 days

Retention cleanup is documented for future scheduled jobs. v0.9.7 does not delete operational records automatically.

## Security Notes

- Do not place Stripe secrets in platform settings.
- Keep secrets in constants or environment configuration.
- Do not use platform administration for customer impersonation.
- CSV exports are limited and spreadsheet formula-protected.
- Diagnostics summaries are sanitised and avoid passwords, secrets, tokens, signatures, payment details, and raw webhook payloads.
