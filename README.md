# QR Buzz

QR Buzz is an open-core WordPress plugin for QR code management, dynamic destinations, static QR types, and privacy-conscious scan analytics.

v0.6.0 is the QR Types & Per-QR Analytics release. QR Buzz now helps users choose what they want to achieve, not just paste a URL.

## Dynamic vs Static QR Codes

Dynamic QR codes use a QR Buzz tracking URL, allowing analytics and editable destinations.

Static QR codes encode the final information directly into the QR image and do not collect analytics.

Use Dynamic URL when you want tracking, editable destinations, scheduling, expiry, fallback URLs, and scan analytics. Use Static QR types for payloads like WiFi, business cards, phone numbers, SMS messages, map locations, and plain text where the final information should be encoded directly.

## Requirements

- WordPress 7.0+
- PHP 8.3+
- MariaDB or MySQL
- Composer for development builds

Release ZIPs include bundled production dependencies, so Composer is not required on the target WordPress site.

## Installing on WordPress

Install the `qr-buzz.zip` file produced by the GitHub Actions release workflow or attached to a GitHub release.

Do not install GitHub's automatic source-code ZIP from the green Code button. That ZIP does not include Composer dependencies, so QR image generation will not be available.

If you download a GitHub Actions artifact, unzip that download first. The artifact contains the actual WordPress plugin file named `qr-buzz.zip`; upload that inner ZIP to WordPress.

If WordPress shows "The link you followed has expired" while uploading, the server upload limit is too small or the request timed out. Either increase `upload_max_filesize` and `post_max_size`, or upload the extracted `qr-buzz` folder directly to `wp-content/plugins/` with FTP or your hosting file manager.

## Features in v0.6.0

- QR type system for Dynamic URL, Static URL, WiFi, Business Card, Email, Phone, SMS, Location, and Text
- Static QR support with direct payload encoding
- Type-specific payload builders for cleaner architecture
- Improved QR Buzz navigation: Dashboard, QR Codes, Create QR, Analytics, and Settings
- Dashboard counts for total, dynamic, and static QR codes
- Quick actions for common QR creation workflows
- Per-QR analytics for dynamic QR codes
- Static QR analytics explanation for non-trackable QR codes
- Basic rule-based QR Insights
- Type-aware QR previews and PNG/SVG downloads
- Database upgrade support for existing v0.5.0 installs

## Dynamic Destination Features

- Edit a QR code destination without changing the shortcode, tracking URL, or printed QR image
- Active and paused QR states, with expired state resolved from optional expiry time
- Optional fallback URL for paused, expired, or unavailable scheduled destinations
- Optional expiry date/time per QR code
- One optional scheduled destination window per QR code
- Redirect preview simulator on the QR edit screen
- Destination history for primary destination, schedule, fallback, status, and expiry changes
- Central destination resolver for primary, scheduled, fallback, paused, and expired outcomes
- Redirect outcome logging for resolved destination, resolution reason, and scan status

## Analytics Features

- Dedicated QR Buzz Analytics admin page
- Date range filters for today, last 7 days, last 30 days, and all time
- Summary cards for total QR codes, total scans, scans today, scans in the last 7 days, most scanned QR code, and latest scan
- Lightweight scan trend chart without a heavy charting dependency
- Top QR codes table sorted by total scans
- Recent scan activity feed with referrer, user-agent summary, and country placeholder
- Basic mobile/desktop/tablet breakdown
- Basic browser-family breakdown
- Individual QR analytics for dynamic QR codes
- Privacy-conscious analytics using hashed IP, user agent, referrer, and country fields

## Database Tables

QR Buzz uses custom tables rather than a custom post type:

- `wp_qrbuzz_qrcodes`
- `wp_qrbuzz_scans`
- `wp_qrbuzz_destination_history`

Existing QR codes are migrated to `dynamic_url` during upgrade. Existing shortcodes, tracking URLs, scan logs, and dynamic destination settings are preserved.

## Development

Install dependencies:

```bash
composer install
```

Run coding standards checks:

```bash
composer lint
```

Apply automatic coding standards fixes where possible:

```bash
composer fix
```

## Release Process

1. Merge the release branch into `main`.
2. Tag the release, for example `v0.6.0`.
3. Push the tag to GitHub.
4. The release workflow installs production dependencies and uploads `qr-buzz.zip` as a build artifact.

The workflow can also be run manually from the GitHub Actions tab.

## Roadmap

Future releases will build on this foundation with multiple smart destination rules, CSV export, retention controls, deeper device/browser reporting, REST API endpoints, WooCommerce integration, live scan notifications, and optional Pro features.
