# QR Buzz

QR Buzz is an open-core WordPress plugin for QR code management, branded QR design, campaigns, smart destinations, hosted accounts, subscriptions, dynamic QR tracking, static QR types, and privacy-conscious scan analytics.

v0.9.6 is the Workspace Experience & UI Foundations release. It polishes the authenticated QR Buzz workspace with a consistent application shell, design tokens, reusable UI components, responsive navigation, improved dashboard and QR Library views, and clearer empty and success states.

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

## Features in v0.9.6

- Consistent authenticated application shell for `/app/*` pages with desktop sidebar, mobile drawer, top bar, skip link, active navigation states, workspace identity, and account actions
- Central CSS design-token layer under `assets/css/` for colours, typography, spacing, shape, shadows, layout, and motion
- Organised customer app CSS files for reset, layout, components, forms, tables, responsive behaviour, and page-specific styling
- Organised customer app JavaScript under `assets/js/` for app shell behaviour and QR Studio preview/save interactions
- Reusable app helpers for icons, page headers, metric cards, badges, empty states, settings navigation, Library tables, Library cards, and recent activity lists
- Redesigned dashboard with greeting, plan badge, key metrics, quick actions, new-user checklist, recent QR codes, recent scan activity, and usage summary
- Improved QR Library with search, campaign/type/status filters, table view, card view, remembered view preference, status badges, campaign metadata, scan counts, last-scan and updated-date visibility
- Refined QR Studio layout with configuration first, live preview beside it on desktop, mobile-friendly single-column behaviour, sticky save actions, preview loading status, and unsaved-change warning
- More consistent Campaigns, Analytics, Workspace/Brand Kit, Billing, and Account settings layouts
- Updated UI architecture documentation, component notes, responsive breakpoints, accessibility notes, and manual UI testing guidance

## Features in v0.9.5

- Hosted frontend routes for `/pricing`, `/register`, `/login`, `/forgot-password`, `/verify-email`, `/logout`, and authenticated `/app/*` pages
- QR Buzz Customer WordPress role for normal hosted users
- Registration, login, password reset, email verification, account settings, workspace settings, and billing settings
- Workspace membership and user profile tables for hosted account state and future team support
- Workspace onboarding flow: choose plan, set workspace details, create first QR or skip to dashboard
- Hosted app shell with dashboard, library, new QR, QR detail, campaigns, analytics, account settings, workspace settings, and billing
- Hosted QR creation for dynamic URL, static URL, WiFi, business card, email, phone, SMS, location, and text QR types
- Customer-side QR preview plus PNG/SVG downloads from the hosted QR detail page
- Subscription table and Stripe webhook event table for local billing state and idempotent event handling
- Stripe test-mode checkout scaffolding for Pro and Business plans
- Webhook handling for checkout completion, subscription updates, invoice paid, and invoice payment failed events
- Conservative entitlement policy: active or trialing subscriptions receive paid-plan access; incomplete, failed, cancelled, or expired subscriptions fall back to Free access
- Backward-compatible upgrade path for existing v0.9.0 workspaces, QR codes, campaigns, brand kit settings, smart destinations, and analytics

## Hosted App Routes

Public routes:

- `/pricing`
- `/register`
- `/login`
- `/forgot-password`
- `/verify-email`
- `/logout`

Authenticated routes:

- `/app/dashboard`
- `/app/onboarding`
- `/app/library`
- `/app/qr/new`
- `/app/qr/{id}`
- `/app/qr/{id}/download/png`
- `/app/qr/{id}/download/svg`
- `/app/campaigns`
- `/app/analytics`
- `/app/settings/account`
- `/app/settings/workspace`
- `/app/settings/billing`

The hosted app is rendered by the plugin and does not require normal customers to use WordPress admin.

## Stripe Test Mode

Stripe is test-mode only in v0.9.6.

Configure with constants or environment variables:

- `QR_BUZZ_STRIPE_SECRET_KEY`
- `QR_BUZZ_STRIPE_WEBHOOK_SECRET`
- `QR_BUZZ_STRIPE_PRO_PRICE_ID`
- `QR_BUZZ_STRIPE_BUSINESS_PRICE_ID`

Webhook endpoint:

`/wp-json/qr-buzz/v1/billing/stripe-webhook`

## QR Design Notes

QR Buzz uses the bundled `endroid/qr-code` library for local QR rendering. QR Buzz supports reliable brand styling such as colours, transparent backgrounds, margin control, error correction, and logos.

Advanced visual QR artwork, such as custom rounded modules, custom eye patterns, gradients, and complex designer module shapes, is intentionally not included yet. The current focus is branded, readable QR codes that remain suitable for real-world printing and scanning.

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

## Campaigns

Campaigns organise QR codes around marketing activity. A QR code can belong to zero or one campaign. Campaign analytics are based on assigned dynamic QR codes because static QR codes do not use QR Buzz tracking.

## Analytics Features

- Dedicated QR Buzz Analytics admin page
- Date range filters for today, last 7 days, last 30 days, and all time
- Summary cards, trend chart, top QR codes, recent scan activity, referrer/device/browser breakdowns
- Individual QR analytics for dynamic QR codes
- Campaign analytics for assigned dynamic QR codes
- Privacy-conscious analytics using hashed IP, user agent, referrer, and country fields

## Database Tables

QR Buzz uses custom tables rather than a custom post type:

- `wp_qrbuzz_workspaces`
- `wp_qrbuzz_workspace_members`
- `wp_qrbuzz_user_profiles`
- `wp_qrbuzz_subscriptions`
- `wp_qrbuzz_webhook_events`
- `wp_qrbuzz_qrcodes`
- `wp_qrbuzz_scans`
- `wp_qrbuzz_destination_history`
- `wp_qrbuzz_campaigns`
- `wp_qrbuzz_destination_rules`

Existing QR codes are preserved during upgrade. Workspace, membership, profile, and subscription defaults are backfilled for existing installs without changing payloads, shortcodes, tracking URLs, scans, campaigns, brand kit settings, or Smart Destination rules.

## Documentation

- `docs/hosted-app-v095.md`
- `docs/design-tokens.md`
- `docs/rest-api.md`
- `docs/workspace-plan.md`
- `docs/diagnostics.md`

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
2. Tag the release, for example `v0.9.6`.
3. Push the tag to GitHub.
4. The release workflow installs production dependencies and uploads `qr-buzz.zip` as a build artifact.

The workflow can also be run manually from the GitHub Actions tab.

## Roadmap

Future releases will build on this foundation with a fuller hosted marketing site, team invitations, production subscription operations, customer API keys, retention controls, deeper reporting, WooCommerce integration, live scan notifications, geo/device/referrer Smart Destination rules, campaign automation, advanced QR module styling, and optional Pro features.
