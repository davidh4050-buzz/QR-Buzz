# QR Buzz REST API

## Smart Destination rules

Authenticated workspace users with the Smart Destinations entitlement can use:

- `GET|POST /wp-json/qr-buzz/v1/assets/{id}/destination-rules`
- `GET|PATCH|DELETE /wp-json/qr-buzz/v1/assets/{id}/destination-rules/{rule_id}`
- `POST /wp-json/qr-buzz/v1/assets/{id}/destination-rules/{rule_id}/duplicate`
- `POST /wp-json/qr-buzz/v1/assets/{id}/destination-rules/{rule_id}/toggle`
- `POST /wp-json/qr-buzz/v1/assets/{id}/destination-rules/reorder` with `rule_ids`
- `POST /wp-json/qr-buzz/v1/assets/{id}/simulate-destination` with optional site-local `datetime`

Write endpoints require normal WordPress REST nonce authentication. Asset queries are scoped to the active workspace, reject static QR codes, and confirm that `rule_id` belongs to both the asset and workspace. Simulation returns `simulation_only: true` and never records a scan.

v0.9.0 introduces an internal REST API under `qr-buzz/v1`.

The API is intended for QR Buzz admin surfaces and future integrations. It is not a public API-key system yet.

## Authentication and permissions

All endpoints require an authenticated WordPress user with `manage_options`.

Asset, campaign, and analytics endpoints also require the `api_access` entitlement on the active workspace plan.

## Response envelope

Successful responses use this shape:

```json
{
  "version": "1",
  "workspace_id": 1,
  "data": {}
}
```

Errors use this shape:

```json
{
  "error": {
    "code": "not_found",
    "message": "QR asset not found."
  }
}
```

## Endpoints

### GET /wp-json/qr-buzz/v1/workspace

Returns active workspace metadata.

### GET /wp-json/qr-buzz/v1/workspace/entitlements

Returns current plan features, limits, usage, and remaining capacity.

### GET /wp-json/qr-buzz/v1/workspace/usage

Returns usage, limits, and remaining capacity.

### GET /wp-json/qr-buzz/v1/assets

Returns workspace QR assets. Supports `page` and `per_page`.

### GET /wp-json/qr-buzz/v1/assets/{id}

Returns one workspace QR asset.

### GET /wp-json/qr-buzz/v1/assets/{id}/analytics

Returns per-QR analytics for dynamic QR assets:

- stats
- scan trend
- recent scans
- referrers
- devices
- browsers
- insights

Static QR assets return a clear non-trackable message.

Optional query parameter: `range=today|7days|30days|all`.

### GET /wp-json/qr-buzz/v1/campaigns

Returns workspace campaigns.

### GET /wp-json/qr-buzz/v1/campaigns/{id}

Returns one workspace campaign.

### GET /wp-json/qr-buzz/v1/campaigns/{id}/analytics

Returns campaign analytics for assigned dynamic QR assets.

Optional query parameter: `range=today|7days|30days|all`.

### GET /wp-json/qr-buzz/v1/analytics/summary

Returns workspace dashboard analytics summary.

### GET /wp-json/qr-buzz/v1/brand-kit

Returns active workspace Brand Kit settings.

## Privacy

The API does not expose raw IP addresses. Recent scan payloads include timestamp, QR ID, referrer, country placeholder, user-agent summary, resolution reason, and scan status.
