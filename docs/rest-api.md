# QR Buzz REST API

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