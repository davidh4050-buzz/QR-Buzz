# Advanced QR Design Notes

QR Buzz v0.9.6 adds a local advanced design renderer for QR Studio.

Supported controls:

- Foreground colour
- Background colour
- Transparent background
- Error correction level
- Quiet zone / margin
- Logo attachment and logo size
- Data module style: square, dots, rounded
- Finder pattern style: square, rounded, circle
- Finder centre style: square, rounded, dot
- Finder colour
- Caption text
- Caption colour
- Caption size

Rendering approach:

- Standard QR codes continue to use the bundled `endroid/qr-code` PNG/SVG writers.
- Advanced designs use the same QR matrix data from `endroid/qr-code`, then render the modules locally so QR Buzz can control shapes and finder styling.
- PNG output requires GD, which is already required by the bundled PNG writer.
- SVG output is generated as inline vector markup and can embed supported logo attachments as data URIs.

Scope notes:

- QR Buzz does not call QuickChart or any external QR rendering API at runtime.
- Gradients, frames, multi-logo layouts, and complex designer masks are deliberately out of scope for this pass.
- Strong scan testing is recommended before printed use whenever dots, circle finders, logos, transparent backgrounds, or unusual colour contrast are used.
