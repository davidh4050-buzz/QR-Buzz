<?php
namespace QRBuzz\Membership;

use QRBuzz\Workspace\WorkspaceService;

class EntitlementService {

    private WorkspaceService $workspaces;
    private PlanRegistry $plans;
    private UsageService $usage;

    public function __construct(?WorkspaceService $workspaces = null, ?PlanRegistry $plans = null, ?UsageService $usage = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->plans = $plans ?: new PlanRegistry();
        $this->usage = $usage ?: new UsageService($this->workspaces);
    }

    public function allows(string $feature): bool {
        $feature = sanitize_key($feature);
        $plan = $this->currentPlan();
        $allowed = !empty($plan['features'][$feature]);
        return (bool) apply_filters('qrbuzz_entitlement_allows', $allowed, $feature, $this->workspaces->current());
    }

    public function limit(string $resource): ?int {
        $resource = sanitize_key($resource);
        $plan = $this->currentPlan();
        $limit = $plan['limits'][$resource] ?? null;
        return $limit === null ? null : (int) $limit;
    }

    public function usage(string $resource): int {
        return $this->usage->usage($resource);
    }

    public function remaining(string $resource): ?int {
        $limit = $this->limit($resource);
        return $limit === null ? null : max(0, $limit - $this->usage($resource));
    }

    public function canCreate(string $resource): bool {
        $limit = $this->limit($resource);
        return $limit === null || $this->usage($resource) < $limit;
    }

    public function canCreateSmartRule(int $qrId): bool {
        if (!$this->allows('smart_destinations')) {
            return false;
        }
        $limit = $this->limit('smart_rules_per_asset');
        return $limit === null || $this->usage->smartRulesForAsset($qrId) < $limit;
    }

    public function summary(): array {
        $workspace = $this->workspaces->current();
        $plan = $this->currentPlan();
        $resources = ['qr_assets', 'dynamic_qr_assets', 'campaigns', 'team_members', 'smart_rules_per_asset', 'analytics_retention_days'];
        $limits = [];
        $usage = [];
        $remaining = [];
        foreach ($resources as $resource) {
            $limits[$resource] = $this->limit($resource);
            $usage[$resource] = in_array($resource, ['smart_rules_per_asset', 'analytics_retention_days'], true) ? null : $this->usage($resource);
            $remaining[$resource] = $usage[$resource] === null ? null : $this->remaining($resource);
        }

        return [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'plan_key' => $workspace->planKey],
            'plan' => ['key' => $workspace->planKey, 'label' => $this->plans->label($workspace->planKey)],
            'features' => $plan['features'],
            'limits' => $limits,
            'usage' => $usage,
            'remaining' => $remaining,
        ];
    }

    private function currentPlan(): array {
        return $this->plans->plan($this->workspaces->current()->planKey);
    }
}
