# Membership and Entitlements

v0.9.0 adds the plan and entitlement architecture needed for QR Buzz to become an open-core product later. It does not add billing, checkout, subscriptions, licensing, public registration, or external plan sync.

Plans are assigned locally from **QR Buzz -> Workspace & Plan**.

## Plans

### Free

Intended for basic testing and small usage.

- Static QR assets
- Dynamic QR assets
- Basic analytics
- Insights
- Campaigns
- Full core QR styling, including colours, module styles, finder styles, and captions
- Limited QR asset count
- Limited dynamic QR asset count
- Limited campaign count

Brand Kit defaults, advanced branding, logo embedding, Smart Destination rules, CSV export, API access, team management, and white label are disabled.

### Pro

Intended for individual/site-owner paid-plan behaviour during development.

- Higher QR and campaign limits
- Advanced analytics
- Smart Destinations
- Full core QR styling
- Advanced branding and Brand Kit defaults
- Logo embedding
- CSV exports
- API access

Team management and white label remain disabled.

### Business

Intended for future team/business plan behaviour.

- Unlimited QR assets, dynamic QR assets, campaigns, and analytics retention in the local registry
- Team management entitlement
- White label entitlement
- Highest Smart Destination rule limit

## Services

- `PlanRegistry` defines plan labels, features, and limits.
- `UsageService` calculates current workspace usage.
- `EntitlementService` checks feature access, remaining limits, and creation permissions.

## Filters

Plans can be adjusted by developers using the `qrbuzz_plans` filter.

Entitlement decisions can be adjusted using the `qrbuzz_entitlement_allows` filter.

## Notes for testing

After upgrading from v0.8.0, the default workspace starts on the Free plan. Core QR styling is available on every plan. To test logo embedding, Brand Kit defaults, advanced branding, Smart Destination rules, CSV exports, or REST API data endpoints, switch the workspace to Pro or Business.