# Changelog

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
