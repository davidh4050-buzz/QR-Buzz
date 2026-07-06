# QR Buzz

QR Buzz is an open-core WordPress plugin for QR code management, branded QR design, campaigns, smart destinations, dynamic QR tracking, static QR types, and privacy-conscious scan analytics.

v0.8.0 is the Design & Identity release. QR Buzz now helps site owners make QR assets look consistent with their brand while preserving the dynamic tracking, campaign, analytics, and Smart Destination features introduced in earlier releases.

## Dynamic vs Static QR Codes

Dynamic QR codes use a QR Buzz tracking URL, allowing analytics, editable destinations, campaigns, Smart Destinations, scheduling, expiry, fallback URLs, and branded downloads.

Static QR codes encode the final information directly into the QR image and do not collect analytics. Static QR assets can still use QR Buzz design settings, themes, logos, and downloads.

Use Dynamic URL when you want tracking, editable destinations, Smart Destination rules, scheduling, expiry, fallback URLs, and scan analytics. Use Static QR types for payloads like WiFi, business cards, phone numbers, SMS messages, map locations, and plain text where the final information should be encoded directly.

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

## Features in v0.8.0

- QR Studio design experience for editing a single QR asset
- Branded QR design controls for foreground colour, background colour, transparent background, quiet zone, and error correction
- Logo support using the WordPress Media Library, with attachment IDs stored instead of raw logo URLs
- PNG downloads and Library thumbnails rendered through the same design-aware QR pipeline
- SVG downloads continue to be supported when the bundled QR library supports them
- QR themes: Classic, Modern, Rounded, Dark, Minimal, High Contrast, and Corporate
- Brand Kit settings for brand name, default colours, default theme, default logo, and default error correction
- New QR assets start with Brand Kit defaults
- Existing QR assets can manually apply the Brand Kit without being changed during upgrade
- Refreshable QR Studio preview powered by the same renderer as downloads
- Redesigned dashboard with quick actions, key metrics, insights, recent activity, and system status
- Navigation polish: Dashboard, Library, New QR, Analytics, Campaigns, and Settings
- Improved empty states and microcopy across the admin UI
- Database upgrade support for v0.7.0 QR assets with safe Classic design defaults

## QR Design Notes

QR Buzz uses the bundled `endroid/qr-code` library for local QR rendering. v0.8.0 supports reliable brand styling such as colours, transparent backgrounds, margin control, error correction, and logos.

Advanced visual QR artwork, such as custom rounded modules, custom eye patterns, gradients, and complex designer module shapes, is intentionally not included in v0.8.0. The current focus is branded, readable QR codes that remain suitable for real-world printing and scanning.

## Logo Recommendations

- Use high error correction when adding a logo.
- Keep logo size modest, usually 10% to 25% of the QR image width.
- Test QR codes before printing or distributing them.
- PNG logo embedding supports raster image formats. SVG QR output remains available, but logo embedding can vary by uploaded image format and scanner compatibility.

## QR Types

- Dynamic URL
- Static URL
- WiFi
- Business Card
- Email
- Phone
- SMS
- Location
- Text

## Smart Destinations

Smart Destinations let a dynamic QR code redirect to different URLs at different times without changing the printed QR code.

Rules are evaluated in priority order. The first active rule whose conditions match wins. If no rule matches, QR Buzz uses the primary destination URL.

Resolver order:

1. Paused QR code fallback/message
2. Expired QR code fallback/message
3. Active Smart Destination rules by priority
4. Legacy scheduled destination fallback, if present
5. Primary destination URL

v0.7.0 introduced date ranges, days of the week, and time-of-day windows. Geo, device, referrer, language, A/B testing, and campaign automation rules are intentionally left for later releases.

## Campaigns

Campaigns organise QR codes around marketing activity. A QR code can belong to zero or one campaign. Campaign analytics are based on assigned dynamic QR codes because static QR codes do not use QR Buzz tracking.

Campaigns can be active or archived. Campaigns can only be deleted when no QR codes are assigned to them.

## Dynamic Destination Features

- Edit a QR code destination without changing the shortcode, tracking URL, or printed QR image
- Active and paused QR states, with expired state resolved from optional expiry time
- Optional fallback URL for paused, expired, or unavailable destinations
- Optional expiry date/time per QR code
- Legacy/simple scheduled destination window per QR code
- Smart Destination rule engine for time-based routing
- Destination simulator on the QR edit screen
- Destination and rule history
- Central destination resolver for primary, smart rule, scheduled, fallback, paused, and expired outcomes
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
- Campaign analytics for assigned dynamic QR codes
- Privacy-conscious analytics using hashed IP, user agent, referrer, and country fields

## Database Tables

QR Buzz uses custom tables rather than a custom post type:

- `wp_qrbuzz_qrcodes`
- `wp_qrbuzz_scans`
- `wp_qrbuzz_destination_history`
- `wp_qrbuzz_campaigns`
- `wp_qrbuzz_destination_rules`

Existing QR codes are preserved during upgrade. Existing scheduled destination fields are copied into legacy-labelled Smart Destination rules, and the old fields are retained for compatibility. v0.8.0 adds design fields to existing QR assets using safe defaults without changing payloads, shortcodes, tracking URLs, scans, campaigns, or Smart Destination rules.

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
2. Tag the release, for example `v0.8.0`.
3. Push the tag to GitHub.
4. The release workflow installs production dependencies and uploads `qr-buzz.zip` as a build artifact.

The workflow can also be run manually from the GitHub Actions tab.

## Roadmap

Future releases will build on this foundation with CSV export, retention controls, deeper device/browser reporting, REST API endpoints, WooCommerce integration, live scan notifications, geo/device/referrer Smart Destination rules, campaign automation, advanced QR module styling, and optional Pro features.
