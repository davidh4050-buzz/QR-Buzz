# Smart Destinations management (v0.9.7 update)

Smart Destination rules are checked from highest priority to lowest. QR Buzz uses the first active rule whose conditions match. When no rule matches, it uses the QR code's primary destination. Paused and expired QR states take precedence, and existing legacy scheduled destinations remain a separate compatibility fallback after Smart Destination rules.

## Customer workflow

Open a dynamic QR and choose **Smart Destinations**. The page shows the primary and fallback destinations, site time and timezone, current resolver result, matched rule, counts, warnings, ordered rules, simulator, and recent history.

Rules can be added, edited, duplicated, activated, deactivated, moved up or down, and deleted. Duplicates start inactive. Deletion is a nonce-protected POST action and does not remove historical scan or audit records.

## Conditions and priority

- Dates and times use the WordPress site timezone and site display formats.
- Days use ISO weekday numbers internally, Monday `1` through Sunday `7`.
- Missing conditions mean the rule always matches.
- `17:00–02:00` is an overnight window and matches from 17:00 through midnight and from midnight through 02:00.
- Priority values are normalized after create, update, delete, duplicate, or reorder operations.

Warnings identify obvious mistakes such as expired ranges, identical conditions, always-matching shadow rules, destinations matching the primary URL, paused/expired QR state, and missing fallback URLs. Warnings do not block otherwise valid rules.

## Simulator

Test the current time or a future local date/time. The simulator calls the production `DestinationResolver`, does not call scan logging, does not update analytics, and does not mutate rules. Results identify paused/expired state, matched rule, readable conditions, destination, and reason.

## Security

Customer routes require authentication, the WordPress `read` capability, active-workspace ownership via scoped repositories, a dynamic QR, Smart Destinations entitlement, a valid nonce for writes, and explicit confirmation that a rule belongs to the requested QR. REST endpoints apply the same workspace and QR/rule checks.

## Platform Inspector

Platform administrators can inspect ordered rules, statuses, readable conditions, the current match, conflict warnings, resolver outcome, and recent destination history. The update does not add unrestricted Platform Admin rule editing.

## Upgrade notes

No schema migration is required. Existing rule IDs, condition JSON, priorities, shortcodes, QR images, tracking URLs, scan analytics, destination history, and legacy schedules are retained. The update remains version `0.9.7` and is safe to install over the earlier v0.9.7 build.
