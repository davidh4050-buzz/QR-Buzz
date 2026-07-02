# QR Buzz

QR Buzz is an open-core WordPress plugin for QR code management and scan analytics.

v0.4.0 is the first proper analytics release. It adds a dedicated analytics dashboard with scan totals, date range filters, scan trends, top QR codes, recent activity, and lightweight device/browser breakdowns.

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

## Features in v0.4.0

- Dedicated QR Buzz Analytics admin page
- Date range filters for today, last 7 days, last 30 days, and all time
- Summary cards for total QR codes, total scans, scans today, scans in the last 7 days, most scanned QR code, and latest scan
- Lightweight scan trend chart without a heavy charting dependency
- Top QR codes table sorted by total scans
- Recent scan activity feed with referrer, user-agent summary, and country placeholder
- Basic mobile/desktop/tablet breakdown
- Basic browser-family breakdown
- Privacy-conscious analytics using existing hashed IP, user agent, referrer, and country fields

## Core Platform Features

- Create, edit, pause, and delete QR codes in WordPress admin
- WordPress-native QR code list table with search, pagination, sorting, thumbnails, and row actions
- Download PNG and SVG QR files
- Generate local QR codes with `endroid/qr-code`
- Encode dynamic tracking URLs such as `/q/ABC123`
- 302 redirect scans to the destination URL
- Log scans to custom database tables
- Hash visitor IP addresses instead of storing raw IPs
- Composer-based development setup
- GitHub Actions release ZIP generation

## Database Tables

QR Buzz uses custom tables rather than a custom post type:

- `wp_qrbuzz_qrcodes`
- `wp_qrbuzz_scans`

The scan table is designed for future analytics, including country lookup, device reporting, campaign metrics, exports, and live activity.

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
2. Tag the release, for example `v0.4.0`.
3. Push the tag to GitHub.
4. The release workflow installs production dependencies and uploads `qr-buzz.zip` as a build artifact.

The workflow can also be run manually from the GitHub Actions tab.

## Roadmap

Future releases will build on this foundation with CSV export, retention controls, deeper device/browser reporting, REST API endpoints, WooCommerce integration, live scan notifications, and optional Pro features.
