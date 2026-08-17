# Subscriptions, plans and analytics export (v0.9.11)

## Commercial architecture

Stripe is the billing authority. QR Buzz remains the product and entitlement authority:

1. Stripe Checkout or Customer Portal changes a subscription.
2. Signed Stripe webhooks update the local subscription record.
3. Stripe Price IDs map centrally to `pro` or `business`.
4. `EntitlementService` resolves the effective plan.
5. Product features call `allows()` and never inspect Stripe IDs.

A successful browser return from Checkout never grants paid access. Active, trialing and past-due subscriptions retain their paid plan. Past due is a deliberate recovery grace state with a Billing warning. Incomplete, incomplete-expired, unpaid and ended subscriptions resolve to Free. Cancellation scheduled for period end retains paid access until Stripe reports the subscription ended.

Customer data is not deleted on downgrade. Existing resources remain stored and readable; creation gates apply the effective plan limits.

## Stripe SDK decision

v0.9.11 retains WordPress HTTP API calls rather than introducing the Stripe PHP SDK. The plugin already has a small Stripe surface, and retaining WordPress HTTP avoids substantially increasing the distributable dependency tree. The implementation provides equivalent lifecycle safeguards for this scope: authenticated server-side requests, timeouts, sanitised errors, five-minute webhook timestamp tolerance, multiple-v1 signature handling, event idempotency, authoritative subscription retrieval, and Price-ID mapping. Re-evaluate the official SDK if future billing work adds tax, invoices, coupons or broader Stripe object handling.

## Required configuration

Configure outside source control:

- `QR_BUZZ_STRIPE_SECRET_KEY`
- `QR_BUZZ_STRIPE_WEBHOOK_SECRET`
- `QR_BUZZ_STRIPE_PRO_PRICE_ID`
- `QR_BUZZ_STRIPE_BUSINESS_PRICE_ID`
- `QR_BUZZ_PRO_DISPLAY_PRICE`
- `QR_BUZZ_BUSINESS_DISPLAY_PRICE`

The display-price values are presentation strings such as `£12`; Stripe remains authoritative for the amount charged.

Do not mix test keys with live Price IDs. Diagnostics reports Test, Live or Unknown without displaying secrets.

## Stripe Dashboard setup

1. Create monthly recurring Pro and Business Products/Prices.
2. Put their Price IDs into the matching QR Buzz configuration values.
3. Configure Customer Portal to allow payment-method updates and invoice history.
4. Allow customers to switch between the configured Pro and Business prices.
5. Configure cancellation at period end.
6. Add the webhook endpoint:
   `https://YOUR-DOMAIN/wp-json/qr-buzz/v1/billing/stripe-webhook`
7. Enable:
   - `checkout.session.completed`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
8. Copy the endpoint signing secret to `QR_BUZZ_STRIPE_WEBHOOK_SECRET`.
9. Run the complete journey in Stripe test mode before replacing every key and Price ID with its live equivalent.

## Analytics export

Pro and Business can export workspace, campaign and per-QR scan analytics. Free requests are denied server-side and returned to an upgrade explanation.

Exports:

- require an authenticated customer and WordPress nonce;
- scope every query to the current workspace;
- verify requested QR/campaign ownership;
- respect the selected date range and plan retention;
- stream in 1,000-row batches;
- include a UTF-8 BOM and human-readable headings;
- prefix cells beginning with `=`, `+`, `-` or `@`;
- record an `analytics_exported` product event without logging scan rows.

Recorded fields exported are timestamp, QR identity, campaign, QR type, destination, device/browser classification, referrer, country, resolved destination, resolution reason and scan status. QR Buzz does not invent unrecorded region, city or operating-system fields.

## Recovery and support

Platform Admin can synchronise a stored subscription from Stripe. This action requires `qrbuzz_manage_platform`, the normal platform nonce and explicit confirmation, and it is audited. The legacy test-plan action refuses to modify records containing Stripe customer or subscription identifiers.
