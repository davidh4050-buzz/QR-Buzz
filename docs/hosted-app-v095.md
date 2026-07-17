# QR Buzz v0.9.5 Hosted App

v0.9.5 introduces the first hosted application prototype for qr-buzz.com.

## Routes

Public and auth routes:

- `/pricing`
- `/register`
- `/login`
- `/logout`
- `/forgot-password`
- `/verify-email`

Authenticated app routes:

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

The hosted app is rendered by the plugin and does not require normal users to enter WordPress admin.

## Authentication

Registration and login use WordPress user/session APIs. New users receive the `qrbuzz_customer` role and are linked to a workspace through `qrbuzz_workspace_members`.

Password reset uses WordPress reset keys with QR Buzz frontend forms.

Email verification is stored in `qrbuzz_user_profiles` using a hashed token. Changing an account email address marks the profile as unverified again and sends a fresh verification link.

## Workspace Onboarding

New users are taken through:

1. plan selection
2. workspace details
3. first QR prompt
4. dashboard

The default workspace is preserved for existing installs. Existing administrators are backfilled as workspace owners.

## Hosted QR Workflows

The hosted app can create QR assets through the existing QR Buzz type and payload services:

- Dynamic URL
- Static URL
- WiFi
- Business Card
- Email
- Phone
- SMS
- Location
- Text

Dynamic QR assets continue to encode the QR Buzz tracking URL and use the redirect engine. Static QR assets encode their final payload directly.

The hosted QR detail page shows the encoded payload/destination, a QR preview, and customer-safe PNG/SVG download links scoped to the current workspace.

## Stripe Test Mode

Stripe is test-mode only in this milestone.

Configure with constants or environment variables:

- `QR_BUZZ_STRIPE_SECRET_KEY`
- `QR_BUZZ_STRIPE_WEBHOOK_SECRET`
- `QR_BUZZ_STRIPE_PRO_PRICE_ID`
- `QR_BUZZ_STRIPE_BUSINESS_PRICE_ID`

Webhook endpoint:

`/wp-json/qr-buzz/v1/billing/stripe-webhook`

Handled events include checkout completion, subscription changes, invoice paid, and invoice payment failed. Webhook IDs are recorded for idempotency.

## Subscription Policy

- `free`: Free entitlements
- `trialing`: selected paid-plan entitlements
- `active`: selected paid-plan entitlements
- `incomplete`, `past_due`, `cancelled`, `expired`: Free entitlements for now

This is intentionally conservative until live billing policy is final.

## Deployment Checklist

- Install the release ZIP, not GitHub source ZIP.
- Confirm PHP 8.3+.
- Configure Stripe test keys and price IDs.
- Configure the Stripe webhook endpoint above.
- Register and complete onboarding with a Free plan.
- Run a Stripe test checkout for Pro and Business.
- Confirm webhook-driven plan activation.
- Confirm payment-failed webhook behaviour falls back to Free-plan access.
- Confirm `/app/dashboard`, QR creation, QR downloads, campaign creation, account settings, workspace settings, and billing settings.
- Confirm existing printed QR codes still redirect.
