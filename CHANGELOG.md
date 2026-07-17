# Changelog

## 0.9.5
- Added hosted frontend routes for registration, login, logout, password recovery, email verification, onboarding, and authenticated `/app/*` pages
- Added QR Buzz Customer WordPress role while keeping WordPress password/session APIs as the authentication foundation
- Added workspace membership table and user profile table for hosted account state, email verification, onboarding progress, and future team support
- Extended workspaces with onboarding status, onboarding step, timezone, website URL, and intended-use fields
- Added subscription and Stripe webhook event tables for test-mode billing state and idempotent webhook handling
- Added hosted onboarding flow for plan selection, workspace setup, first-QR prompt, and dashboard entry
- Added Free plan activation and Stripe test-mode checkout scaffolding for Pro and Business using configured environment/constants
- Added Stripe webhook signature verification and local subscription/plan sync for checkout and subscription events
- Added hosted app shell with dashboard, library, new QR, QR detail, campaigns, analytics, account settings, workspace settings, and billing settings
- Added frontend QR and campaign creation paths that reuse existing QR Buzz repositories and payload builders
- Updated entitlement handling so inactive or incomplete subscription states fall back to Free-plan access
- Updated plugin metadata for v0.9.5

## 0.9.0
- Added default workspace ownership foundation with `qrbuzz_workspaces` and workspace-scoped QR, campaign, rule, brand kit, and analytics queries
- Added local Free, Pro, and Business plan registry for development/testing without billing or external subscription services
- Added entitlement and usage services for feature availability, QR asset limits, dynamic QR limits, campaign limits, Smart Destination rule limits, CSV export access, API access, branding, and logo embedding
- Added Workspace & Plan admin area with local plan switching, usage cards, feature availability, and CSV export actions
- Added Diagnostics admin area with version, schema, renderer, SVG, REST, database, PHP, WordPress, workspace, and plan information while excluding secrets and raw scan data
- Added workspace-scoped CSV exports for QR assets and campaigns with capability checks, nonce validation, and spreadsheet formula-injection protection
- Added internal REST API namespace `qr-buzz/v1` for workspace, entitlements, usage, assets, campaigns, analytics summaries, per-QR analytics, campaign analytics, and brand kit data
- Added workspace-aware Brand Kit storage while preserving fallback to legacy v0.8 settings
- Added database upgrade/backfill routines for existing v0.8 QR assets, campaigns, destination rules, and legacy scheduled-destination migrations
- Added supporting architecture, database, membership, and REST API documentation
- Updated plugin metadata for v0.9.0

## 0.8.0
- Added QR Studio design experience for editing individual QR assets
- Added QR design controls for foreground colour, background colour, transparent background, error correction, quiet zone, logo, and logo size
- Added WordPress Media Library logo selection with attachment ID storage
- Added QR themes: Classic, Modern, Rounded, Dark, Minimal, High Contrast, and Corporate
- Added Brand Kit settings for brand name, default colours, default QR theme, default logo, and default error correction
- Added manual Apply Brand Kit action for existing QR assets
- Updated QR generation so previews, Library thumbnails, PNG downloads, and SVG downloads use the same design-aware rendering pipeline
- Added refreshable QR Studio preview with AJAX rendering and error handling
- Redesigned the dashboard with quick actions, key metrics, recent activity, QR insights, and system status
- Renamed QR Codes navigation to Library and Create QR navigation to New QR
- Improved empty states, microcopy, status styling, spacing, and WordPress-native admin polish
- Added design schema fields with safe defaults for upgrades from v0.7.0
- Updated plugin metadata for v0.8.0

## 0.7.0
- Added Campaigns with create, edit, archive/unarchive, safe delete, and campaign analytics workflows
- Added campaign assignment for dynamic and static QR codes
- Added campaign column and campaign filter to the QR code list
- Added campaign-level analytics for QR totals, dynamic/static split, scans, top QR code, trend, recent activity, referrers, devices, and browsers
- Added Smart Destinations rule table and repository for time-based destination rules
- Added date range, day-of-week, and time-of-day windows
- Added Smart Destinations rule builder on dynamic QR edit screens
- Added admin destination simulator with future date/time testing
- Updated the destination resolver so paused and expired states override rules, active rules run by priority, and primary destination remains the fallback
- Migrated existing v0.5/v0.6 scheduled destination fields into legacy-labelled Smart Destination rules while keeping the old fields for compatibility
- Extended destination history to include rule create/update/delete/activation changes
- Expanded QR Insights with Smart Destination and fallback recommendations
- Added campaign insights for no-scan, static QR, and scan trend states
- Updated plugin metadata for v0.7.0

## 0.6.0
- Added QR type system for Dynamic URL, Static URL, WiFi, Business Card, Email, Phone, SMS, Location, and Text QR codes
- Added static QR code support with direct payload encoding and no redirect tracking
- Added payload builder service layer for type-specific QR payload formatting
- Added QR Buzz dashboard navigation for Dashboard, QR Codes, Create QR, Analytics, and Settings
- Added dashboard counts for dynamic and static QR codes plus quick actions
- Added per-QR analytics pages for dynamic QR codes
- Added static QR analytics message explaining the difference from tracked dynamic QR codes
- Added deterministic QR Insights for scan activity, paused/expired state, scheduled destinations, and device usage
- Updated downloads and list previews so dynamic codes encode tracking URLs and static codes encode direct payloads
- Added database upgrade fields for type, payload data, static payload, and trackability
- Migrated existing QR codes to `dynamic_url` while preserving shortcodes, tracking URLs, and analytics
- Updated plugin metadata for v0.6.0
