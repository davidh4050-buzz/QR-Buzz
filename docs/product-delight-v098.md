# QR Buzz v0.9.8 Product Delight

v0.9.8 is a focused interaction and visual refinement release for the hosted QR Buzz workspace.

## Principles

- Elegant: restrained, balanced, uncluttered, and consistent.
- Alive: responsive hover/focus feedback, subtle loading states, and clear save feedback.
- Never gimmicky: no heavy animation, phone mockups, external previews, or motion that competes with the QR code.

## Motion

Motion is centralised in CSS variables:

- `--qrb-motion-fast`: 120ms
- `--qrb-motion-standard`: 180ms
- `--qrb-motion-slow`: 240ms
- `--qrb-ease-standard`: `cubic-bezier(0.2, 0, 0, 1)`
- `--qrb-ease-emphasised`: `cubic-bezier(0.2, 0, 0, 1.2)`

Non-essential motion is disabled when `prefers-reduced-motion: reduce` is active.

## QR Library Cards

QR cards use a restrained status strip:

- Active: teal
- Paused: amber
- Expired: red
- Scheduled or informational states: blue
- Unknown or draft-like states: neutral border colour

Cards may lift by roughly 2px on hover/focus, deepen the shadow slightly, and scale QR artwork subtly. Actions remain accessible without relying on hover.

## QR Health

QR health is deterministic and intentionally modest:

- Healthy: configuration looks ready.
- Quiet: no scans yet or no fallback destination is configured.
- Needs attention: expired QR codes.
- Paused: intentionally paused QR codes.

Health should guide owners without implying a technical failure when a QR simply has low traffic.

## Tooltips and Tips

Tooltips explain concepts such as Smart Destinations, static versus dynamic QR codes, QR health, analytics tracking, and design safety. They are keyboard focusable and should not contain essential-only information.

Contextual tips are dismissible and persisted in browser storage so they do not interrupt users repeatedly.

## Empty and Loading States

Empty states use concise copy, one clear action, and the QR Buzz block motif. Loading states use a small block-motif indicator and accessible text labels.

## Manual Design QA

Check:

- Dashboard hierarchy, quick actions, metrics, activity timeline, and usage bars.
- QR Library table and card views at desktop and mobile widths.
- Card hover/focus states without layout shift.
- QR Studio accordion state, live preview, unsaved indicator, media selector, and sticky save actions.
- QR detail Insights, Smart Destinations, and Analytics anchors.
- Reduced-motion behavior.
- Keyboard focus visibility across buttons, cards, navigation, forms, and tables.
- Platform Admin remains professional and readable.
