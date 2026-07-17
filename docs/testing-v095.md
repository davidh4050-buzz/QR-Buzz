# QR Buzz v0.9.5 Acceptance Checklist

Use this checklist on a staging WordPress site before tagging v0.9.5.

## Upgrade

- Install over a working v0.9.0 build.
- Confirm activation completes without PHP warnings.
- Confirm existing QR codes, campaigns, Smart Destination rules, brand kit settings, and scan analytics remain visible.
- Confirm an existing administrator owns the default workspace.

## Hosted Account Flow

- Open `/register` while logged out.
- Register a new user with a strong password.
- Confirm the user receives the `qrbuzz_customer` role.
- Confirm the user is redirected to onboarding.
- Complete Free plan onboarding.
- Confirm `/app/dashboard` loads after onboarding.
- Log out and log in again through `/login`.
- Request a password reset from `/forgot-password`.
- Change account name and email from `/app/settings/account`.
- Confirm changing email marks the account unverified and sends a new verification email.

## Workspace and App Shell

- Confirm `/app/library`, `/app/qr/new`, `/app/campaigns`, `/app/analytics`, `/app/settings/account`, `/app/settings/workspace`, and `/app/settings/billing` load for the customer.
- Confirm logged-out visitors are redirected to `/login` when opening `/app/*` routes.
- Confirm workspace settings save for the workspace owner.

## QR Creation and Downloads

Create one QR asset for each type:

- Dynamic URL
- Static URL
- WiFi
- Business Card
- Email
- Phone
- SMS
- Location
- Text

For each created asset:

- Confirm the QR detail page loads.
- Confirm PNG download works.
- Confirm SVG download works if SVG is supported by the bundled QR library.

For Dynamic URL:

- Confirm the QR encodes the tracking URL.
- Confirm scans redirect through QR Buzz.
- Confirm scans appear in analytics.

For static types:

- Confirm the QR encodes the final payload directly.
- Confirm static QR assets do not create redirect-tracking scans.

## Campaigns

- Create a campaign from `/app/campaigns`.
- Create a QR asset assigned to the campaign.
- Confirm the campaign list shows the QR count.

## Stripe Test Mode

- Configure test secret key, webhook secret, Pro price ID, and Business price ID.
- Start a Pro checkout from onboarding or billing.
- Complete checkout with a Stripe test card.
- Confirm checkout/session and subscription webhook events activate paid-plan entitlements.
- Send `invoice.payment_failed` and confirm entitlement fallback to Free.
- Send `invoice.paid` and confirm entitlement restoration for the paid plan.
- Confirm duplicate webhook deliveries are ignored safely.

## Release ZIP

- Run the release workflow.
- Download the artifact.
- Upload the inner `qr-buzz.zip` through WordPress Plugins.
- Confirm activation and hosted routes work from the installed ZIP.
