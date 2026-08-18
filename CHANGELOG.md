# Changelog

## 0.9.11
- Enabled full core QR visual styling for Free accounts
- Refined branding entitlements to separate QR styling from premium Brand Kit and logo features
- Added consistent premium gating for logo embedding while preserving existing branded QR codes
- Aligned QR Studio styling across preview, save, reload, PNG, and SVG output
- Added Stripe Checkout subscriptions for Pro and Business with existing-customer reuse
- Added Stripe Customer Portal billing management and plan changes
- Added Stripe Price-to-QR Buzz plan mapping and webhook-authoritative entitlement changes
- Added renewal, cancellation-pending, payment-issue and past-due grace presentation
- Added secure workspace, campaign and per-QR analytics CSV exports for Pro and Business
- Added analytics retention bounding, streamed CSV generation and spreadsheet formula-injection protection
- Added contextual premium upgrade prompts and signup plan intent
- Added Stripe configuration diagnostics and safe platform subscription reconciliation
- Protected genuine Stripe subscriptions from platform test-plan overrides
- Preserved workspaces and customer content through downgrades and cancellation

## 0.9.10
- Redesigned QR Buzz registration and login experience
- Added Google OpenID Connect authentication and provider-based identity records
- Added safe Google linking after password authentication for matching existing accounts
- Defaulted new accounts to one Free workspace and subscription without Stripe checkout
- Refactored legacy plan/workspace onboarding into direct first-QR creation
- Added first-QR guidance and completion actions, verification notice, and state-aware checklist
- Added authentication diagnostics, events, migration logic, and Google setup documentation
- Preserved existing users, workspaces, subscriptions, QR codes, analytics, and Asset Library data

## 0.9.9
- Added a dedicated hosted Asset Library at `/app/assets`
- Added QR Buzz-controlled upload storage for per-workspace, per-user logo assets under WordPress uploads
- Added the `qrbuzz_assets` table and `logo_asset_id` QR code column with upgrade-safe schema handling
- Added PNG, JPG, JPEG, and WebP upload validation with 5 MB and 6000px dimension limits
- Added Asset Library thumbnail grid, empty state, refresh, rename, and guarded delete actions
- Added QR Studio logo picker and upload workflow backed by the Asset Library
- Preserved existing `logo_attachment_id` Media Library logos as a legacy rendering fallback
- Updated QR previews, PNG downloads, and SVG downloads to resolve QR Buzz asset logos
- Updated documentation and plugin metadata for v0.9.9

## 0.9.8
- Applied the further UI refinements: `#d0d7dc` background, transparent sidebar, dark navigation text, full-width header, top-left logo, and `#217a9b` buttons with white text
- Fixed notice dismissal and stale notice query parameters
- Added confirmed, nonce-protected QR deletion from QR Library cards
- Added shared motion, easing, button, focus, tooltip, loading, QR block motif, usage bar, and dismissible tip patterns for the hosted workspace
- Refined the workspace dashboard welcome area, quick actions, activity timeline, metrics, usage presentation, and first-use guidance
- Improved QR Library card interactions with restrained hover/focus lift, QR artwork framing, status accent strips, health indicators, and clearer primary/secondary actions
- Added deterministic QR health messaging for active, quiet, paused, and expired QR states
- Improved QR Studio with progressive disclosure sections, persisted accordion state, unsaved-change indicator, improved preview loading feedback, and contextual help
- Refined per-QR detail sections with anchored Analytics and Smart Destinations blocks, clearer tooltips, matched-rule emphasis, and plain-language analytics observations
- Added dismissible workspace tips and accessible dismissible success notifications
- Improved table hover/focus readability, analytics chart interactions, and reduced-motion handling
- Updated documentation for v0.9.8 design principles, component states, motion, accessibility, and manual design QA
- Updated plugin metadata for v0.9.8

## 0.9.7
- Added dedicated `/platform-admin/*` platform administration area with QR Buzz application styling
- Added `qrbuzz_manage_platform` capability and QR Buzz Platform Admin role
- Added platform overview dashboard with operational metrics, attention-required card, recent activity, and "What's happening today?" observations
- Added platform-wide Users, Workspaces, Subscriptions, QR Inspector, Campaigns, Product Analytics, System Health, Diagnostics, Webhooks, Errors, Audit Log, Feature Flags, and Settings sections
- Added custom tables for audit events, platform activity events, application errors, webhook logs, feature flags, and diagnostic snapshots
- Added sanitised support diagnostics and safe support actions for verification email resend, onboarding reset, workspace suspension/reactivation, test plan assignment, webhook retry review, error status updates, and diagnostics snapshots
- Added Stripe webhook diagnostic logging without storing raw payloads
- Added product activity event recording for registration, workspace creation, onboarding completion, QR create/update/download, campaign create/update, and subscription changes
- Added CSV exports for key platform administration views with capability checks and spreadsheet formula protection
- Updated documentation for platform administration, capability setup, event logging, feature flags, diagnostics, and retention guidance
- Updated plugin metadata for v0.9.7

## 0.9.6
- Added a polished authenticated workspace shell with desktop sidebar, mobile drawer, top bar, skip link, active navigation states, workspace identity, and account actions
- Added a customer app design-token and CSS architecture under `assets/css/`
- Added customer app JavaScript organisation under `assets/js/` for shell interactions and QR Studio behaviour
- Added reusable UI helpers for icons, page headers, metric cards, badges, empty states, settings navigation, Library tables/cards, and recent activity lists
- Added advanced QR Studio design controls for data module style, finder pattern style, finder centre style, finder colour, captions, caption colour, and caption size
- Added local styled PNG/SVG rendering for advanced QR designs while keeping standard QR rendering on the bundled QR library path
- Added a design schema upgrade marker so existing v0.9.6 installs can receive the advanced design fields cleanly
- Redesigned the hosted dashboard with clearer greeting, plan badge, key metrics, quick actions, new-user checklist, recent QR codes, recent scan activity, and usage summary
- Improved the hosted QR Library with search, campaign/type/status filters, table and card views, remembered view preference, status badges, campaign metadata, scan counts, last scan, updated date, and quick actions
- Refined hosted QR Studio layout with configuration-first flow, live preview, sticky save actions, loading state, and unsaved-change warning
- Improved Campaigns, Analytics, Account, Workspace/Brand Kit, and Billing pages for visual consistency and clearer empty/error/success states
- Updated documentation for the v0.9.6 workspace UI architecture and manual testing checklist
- Updated plugin metadata for v0.9.6

## 0.9.5
- Added hosted frontend routes for registration, login, logout, password recovery, email verification, onboarding, and authenticated `/app/*` pages
- Added QR Buzz Customer WordPress role while keeping WordPress password/session APIs as the authentication foundation
- Added workspace membership table and user profile table for hosted account state, email verification, onboarding progress, and future team support
- Extended workspaces with onboarding status, onboarding step, timezone, website URL, and intended-use fields
- Added subscription and Stripe webhook event tables for test-mode billing state and idempotent webhook handling
- Added hosted onboarding flow for plan selection, workspace setup, first-QR prompt, and dashboard entry
- Added Free plan activation and Stripe test-mode checkout scaffolding for Pro and Business using configured environment/constants
- Added Stripe webhook signature verification and local subscription/plan sync for checkout, subscription, invoice paid, and payment-failed events
- Added hosted app shell with dashboard, library, new QR, QR detail, campaigns, analytics, account settings, workspace settings, and billing settings
- Added frontend QR and campaign creation paths that reuse existing QR Buzz repositories and payload builders
- Added customer-side hosted QR previews plus PNG and SVG download routes scoped to the current workspace
- Added account email updates with automatic reverification
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

## 0.5.0
- Added Dynamic Destinations so QR codes can change destination without changing shortcode, tracking URL, or printed QR image
- Added active and paused status handling with computed expired state
- Added optional fallback URL for paused, expired, or unavailable scheduled destinations
- Added optional expiry date/time per QR code
- Added one scheduled destination window per QR code
- Added central destination resolver for primary, scheduled, fallback, paused, and expired outcomes
- Added redirect preview simulator on the QR edit screen
- Added destination history table and recent history display on the edit screen
- Added scan outcome fields for resolved destination, resolution reason, and scan status
- Added database upgrade support from v0.4.0 custom tables
- Updated plugin metadata for v0.5.0

## 0.4.0
- Added dedicated QR Buzz Analytics admin page
- Added analytics date range filters for today, last 7 days, last 30 days, and all time
- Added analytics summary cards for QR totals, scan totals, scans today, scans in the last 7 days, most scanned QR code, and latest scan
- Added lightweight scan trend chart without a heavy charting dependency
- Added top QR codes table sorted by scan count
- Added recent scan activity feed with referrer, user-agent summary, and country placeholder
- Added basic device type breakdown from stored user-agent data
- Added basic browser family breakdown from stored user-agent data
- Added analytics repository query layer and lightweight user-agent parser
- Updated plugin metadata for v0.4.0

## 0.3.0
- Added WordPress-native QR code list table
- Added QR code search, pagination, and sortable columns
- Added QR preview thumbnails to the admin list
- Added row actions for edit, delete, activate/deactivate, copy tracking URL, download PNG, and download SVG
- Added secure admin download endpoints for PNG and SVG QR files
- Added dashboard summary cards for QR totals, scan totals, most scanned QR code, latest scan, and recently created QR codes
- Added repository methods for paginated management queries and dashboard statistics
- Improved create/edit form copy, notices, empty states, and destructive-action confirmations
- Updated plugin metadata for v0.3.0

## 0.2.0
- Added Composer development setup with `endroid/qr-code`
- Added release ZIP generation through GitHub Actions
- Added custom QR code and scan database tables
- Added database installer and lightweight upgrade routine
- Added QR code model, repository, and shortcode generation
- Added QR management admin screen for create, edit, pause, delete, and list workflows
- Added local PNG QR generation for tracking URLs
- Added admin diagnostics when bundled QR dependencies are missing
- Added redirect engine for `/q/{shortcode}` tracking URLs
- Added scan logging with hashed IP addresses, user agent, referrer, and scan time
- Added scan counts, last scan, and recent scan history in admin
- Removed dependency on the external QR API
- Added nonce verification, capability checks, sanitisation, escaping, and prepared SQL usage across v0.2.0 workflows
- Updated plugin metadata for v0.2.0

## 0.1.0
- Initial release
- Admin QR generator
- External QR API integration
