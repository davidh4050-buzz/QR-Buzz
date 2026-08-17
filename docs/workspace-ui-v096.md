# QR Buzz v0.9.6 Workspace UI Foundations

v0.9.6 turns the hosted `/app/*` workspace into a more consistent customer application while preserving the existing WordPress-powered routes and QR functionality.

## Structure

The authenticated app is still routed by `QRBuzz\App\HostedAppController`, but the interface foundations now live in app assets:

- `assets/css/tokens.css` defines design tokens for colour, type, spacing, shape, shadows, layout, and motion.
- `assets/css/reset.css`, `layout.css`, `components.css`, `forms.css`, `tables.css`, and `responsive.css` provide shared UI layers.
- `assets/css/pages/` contains page-specific refinements for Dashboard, Library, and QR Studio.
- `assets/js/app-shell.js` controls the mobile drawer, view switching, and shell interactions.
- `assets/js/pages/qr-studio.js` controls live preview, logo picker integration, loading state, and unsaved-change warnings.

The assets are loaded inline by the routed app page so the customer workspace remains independent from the active public WordPress theme.

## Components

Reusable PHP helpers now render common UI patterns:

- application shell and navigation
- SVG icons
- page headers
- metric cards
- status badges
- empty states
- settings section navigation
- Library table and card views
- recent QR and scan activity lists

Prefer adding or extending these helpers before creating page-specific markup.

## Navigation

Primary navigation:

- Dashboard
- Library
- New QR
- Campaigns
- Analytics
- Brand Kit
- Settings

Icons are centralised in `HostedAppController::icon()` and use a consistent inline SVG stroke style. Do not use emoji or Dashicons in the customer workspace.

## Responsive Behaviour

Breakpoints:

- `820px`: desktop sidebar becomes a mobile drawer.
- `560px`: cards, filters, and metrics collapse to a single column.

The mobile drawer is keyboard-operable, closes with Escape, and prevents background scrolling while open.

## Accessibility Notes

The app shell includes:

- skip-to-content link
- navigation landmarks
- visible focus styles
- active navigation state with `aria-current`
- labelled mobile menu button
- status toast region
- reduced-motion friendly transitions

Charts and data tables keep textual headings and summaries so information is not conveyed only by colour.

## Manual UI Testing Checklist

- Visit `/app/dashboard`, `/app/library`, `/app/qr/new`, `/app/campaigns`, `/app/analytics`, and settings pages.
- Confirm active navigation state follows the current page.
- At mobile width, open and close the drawer with the Menu button and Escape.
- Create and edit a QR code in QR Studio.
- Confirm QR Studio preview refreshes and unsaved-change warning appears after edits.
- Switch Library between table and card views.
- Use Library search and campaign/type/status filters.
- Confirm empty states appear for a new workspace or filtered no-results state.
- Confirm download links and QR detail pages still work.
- Confirm no public theme styling visibly leaks into the customer workspace.
