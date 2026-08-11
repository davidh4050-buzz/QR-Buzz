# QR Buzz Architecture

QR Buzz is organised around small service and repository classes rather than putting product logic directly in admin views.

## Current modules

- `Admin`: WordPress admin screens, admin-post handlers, list tables, downloads, exports, and diagnostics.
- `Analytics`: workspace-scoped analytics query services, user-agent summaries, per-QR analytics, campaign analytics, and insights.
- `Database`: table names, installation/upgrades, repositories, and schema backfills.
- `Membership`: local plan registry, usage calculation, and entitlement checks.
- `Models`: lightweight data models for QR codes, campaigns, workspaces, and Smart Destination rules.
- `QR`: QR rendering, design settings, brand kit settings, type registry, and payload builders.
- `Redirect`: shortcode routing, destination resolution, and redirect result handling.
- `REST`: internal authenticated REST API.
- `Workspace`: current workspace lookup and local plan assignment.

## Workspace model

v0.9.0 introduces one default workspace per install. Existing QR assets, campaigns, and Smart Destination rules are backfilled into this workspace during upgrade.

The active workspace is resolved by `WorkspaceService`. Repositories use this service so admin lists, analytics, campaigns, Smart Destination rules, Brand Kit settings, exports, and REST responses operate inside the same workspace boundary.

Shortcode redirect lookup remains global by shortcode so existing printed QR codes keep working. Redirect resolution then uses the QR code record that was found.

## Entitlements

Plan and entitlement logic is centralised in `Membership`:

- `PlanRegistry` defines local Free, Pro, and Business plans.
- `UsageService` counts current workspace usage.
- `EntitlementService` answers whether a feature is available and whether a resource can be created.

Admin save handlers call entitlement checks before creating QR assets, campaigns, branded designs, logos, or Smart Destination rules.

## REST API

The REST API is internal and administrator-only in v0.9.0. Asset, campaign, and analytics endpoints also require the `api_access` entitlement. Public API keys and third-party app access are intentionally deferred.

## Privacy

QR Buzz does not store raw IP addresses. Scan logging continues to store an IP hash, user agent, referrer, country placeholder, resolved destination, resolution reason, and scan status. Diagnostics and REST scan summaries avoid exposing raw IPs.