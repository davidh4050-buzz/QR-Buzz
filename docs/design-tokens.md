# QR Buzz Design Tokens

The v0.9.6 hosted app uses CSS custom properties in the frontend shell and stores the customer workspace token layer in `assets/css/tokens.css`.

Current tokens:

- `--qrb-primary`
- `--qrb-accent`
- `--qrb-bg`
- `--qrb-surface`
- `--qrb-border`
- `--qrb-text`
- `--qrb-muted`
- `--qrb-radius`

Reusable primitives in the initial shell:

- `.qrb-button`
- `.qrb-button-primary`
- `.qrb-card`
- `.qrb-hero`
- `.qrb-form`
- `.qrb-table`
- `.qrb-metrics`
- `.qrb-plan-grid`
- `.qrb-alert`

This is deliberately lightweight so QR Buzz can be re-skinned later without adopting a large frontend framework.
