# Changelog

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
