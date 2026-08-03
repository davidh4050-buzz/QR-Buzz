# Smart Destinations v0.9.7 acceptance checklist

- [ ] Upgrade an existing v0.9.7 installation and confirm existing rules, IDs, QR URLs, images, scans, and legacy schedules remain unchanged.
- [ ] Confirm add, edit, delete, inactive duplication, activation, deactivation, and Move Up/Move Down.
- [ ] Confirm the first active matching rule wins and the current-match indicator agrees with a real redirect.
- [ ] Test current and future simulation in the configured site timezone and confirm scan totals do not change.
- [ ] Confirm paused and expired QR codes override rules and use fallback behaviour.
- [ ] Confirm static QR codes, logged-out requests, missing entitlements, invalid nonces, cross-workspace QR IDs, and mismatched rule/QR IDs are rejected.
- [ ] Confirm rule events appear in QR History and Platform Audit Log.
- [ ] Confirm Platform QR Inspector shows ordered rules, readable conditions, warnings, match, resolver result, and history.
- [ ] Check keyboard and touch operation at 320, 375, 768, 1024, and 1440 pixels with no unintended horizontal page scrolling.
- [ ] Install the release ZIP on WordPress 7.0+ with PHP 8.3+ and run the WordPress/PHP test suite.
