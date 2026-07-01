# QR Buzz

QR Buzz is an open-core WordPress plugin for QR code management and scan analytics.

v0.2.0 establishes the core platform: custom database tables, dynamic tracking URLs, local QR generation, redirect-based scan logging, and a release build workflow.

## Requirements

- WordPress 7.0+
- PHP 8.3+
- MariaDB or MySQL
- Composer for development builds

Release ZIPs include bundled production dependencies, so Composer is not required on the target WordPress site.

## Features in v0.2.0

- Create, edit, pause, and delete QR codes in WordPress admin
- Generate local PNG QR codes with `endroid/qr-code`
- Encode dynamic tracking URLs such as `/q/ABC123`
- 302 redirect scans to the destination URL
- Log scans to custom database tables
- Hash visitor IP addresses instead of storing raw IPs
- Show scan counts, last scan, and recent scan history
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
2. Tag the release, for example `v0.2.0`.
3. Push the tag to GitHub.
4. The release workflow installs production dependencies and uploads `qr-buzz.zip` as a build artifact.

The workflow can also be run manually from the GitHub Actions tab.

## Roadmap

Future releases will build on the v0.2.0 foundation with deeper analytics, device/browser reporting, CSV export, REST API endpoints, WooCommerce integration, live scan notifications, and optional Pro features.
