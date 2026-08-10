# QR Buzz Database Schema

QR Buzz uses custom WordPress database tables. Table names use the active WordPress prefix, for example `wp_qrbuzz_qrcodes`.

## Tables

### qrbuzz_workspaces

Introduced in v0.9.0.

- `id`
- `name`
- `slug`
- `status`
- `owner_user_id`
- `plan_key`
- `created_at`
- `updated_at`

The installer creates a default workspace and assigns existing data to it.

### qrbuzz_qrcodes

Stores QR asset records. v0.9.0 adds `workspace_id` and an index for workspace-scoped admin and analytics queries.

Important fields include:

- `workspace_id`
- `name`
- `destination_url`
- `shortcode`
- `status`
- `fallback_url`
- `expires_at`
- `scheduled_url`
- `scheduled_start_at`
- `scheduled_end_at`
- `type`
- `payload_data`
- `static_payload`
- `is_trackable`
- `campaign_id`
- design fields such as theme, colours, transparency, error correction, margin, logo, logo size, module style, finder style, finder colour, and caption settings

### qrbuzz_scans

Stores privacy-conscious scan records.

- `qr_id`
- `scanned_at`
- `ip_hash`
- `user_agent`
- `referrer`
- `country`
- `destination_resolved`
- `resolution_reason`
- `scan_status`

v0.9.0 adds a composite scan index through the installer for faster per-QR scan history.

### qrbuzz_campaigns

Stores campaign records. v0.9.0 adds `workspace_id` and workspace-scoped repository access.

### qrbuzz_destination_rules

Stores Smart Destination rules. v0.9.0 adds `workspace_id` and keeps v0.7+ time-based rule behaviour.

### qrbuzz_destination_history

Stores destination and rule history entries. This remains linked to QR assets by `qr_id`.

### qrbuzz_audit_events

Introduced in v0.9.7. Stores append-oriented administrator and support actions with actor, related workspace/user/entity, redacted before/after data, metadata, and timestamp.

### qrbuzz_platform_events

Introduced in v0.9.7. Stores meaningful product activity events for platform analytics and "What's happening today?" observations.

### qrbuzz_application_errors

Introduced in v0.9.7. Stores grouped, sanitised QR Buzz application errors with severity, category, occurrence count, status, and timestamps.

### qrbuzz_webhook_logs

Introduced in v0.9.7. Stores Stripe webhook diagnostic records without retaining full raw payloads.

### qrbuzz_feature_flags

Introduced in v0.9.7. Stores lightweight global/workspace/user feature flags. Flags do not replace plan entitlements.

### qrbuzz_diagnostic_snapshots

Introduced in v0.9.7. Stores sanitised support diagnostics snapshots.

## Upgrade behaviour

On activation or version upgrade, `Installer::activate()` runs `dbDelta`, creates the default workspace if required, and backfills missing `workspace_id` values for QR assets, campaigns, and destination rules.

Existing shortcodes, tracking URLs, scans, destination history, campaign assignments, Brand Kit values, and design fields are preserved.
