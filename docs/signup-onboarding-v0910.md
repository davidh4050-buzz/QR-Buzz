# Signup and onboarding (v0.9.10)

New email and Google accounts resolve into the same WordPress user, QR Buzz profile, workspace membership, Free subscription, and hosted session model. Registration no longer invokes plan selection, workspace configuration, or Stripe Checkout. The initial state is `new / first_qr`; the first successful QR save changes both workspace and profile to `complete / complete`.

Google uses the OAuth 2.0 authorization-code OpenID Connect flow. State and nonce are single-use, short-lived transients. The server exchanges the code, validates the ID token with Google's token validation endpoint, then checks issuer, audience, expiry, nonce, subject, and verified-email state. No access or refresh token is retained. `qrbuzz_auth_identities` uniquely keys `provider + provider_subject` and allows future providers without changing the customer model.

If a verified Google email matches an existing account, QR Buzz stores a short-lived pending link and asks the user to authenticate with their existing password. Only a successful matching-account login creates the identity link; workspaces and customer data are never duplicated. A server-validated verified Google email marks the QR Buzz profile verified, so no redundant verification message is sent.

The database migration is idempotent. Workspaces containing QR codes are considered established and completed. Incomplete legacy `plan` and `workspace` states move to `new / first_qr`. Existing plan and subscription rows are not rewritten.

Manual boundary tests: new email registration, Google registration, linked Google login, matching-email collision and password proof, invalid/expired state, wrong nonce/audience, duplicate subject, password recovery, verification/resend throttling, customer `/wp-admin/` isolation, expired session, mobile layouts, first QR save, and established v0.9.9 upgrade.
