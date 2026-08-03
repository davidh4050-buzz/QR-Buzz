<?php
namespace QRBuzz\REST;

use QRBuzz\Analytics\CampaignAnalyticsService;
use QRBuzz\Analytics\DateRange;
use QRBuzz\Analytics\PerQRAnalyticsService;
use QRBuzz\Analytics\QRInsightService;
use QRBuzz\Database\CampaignRepository;
use QRBuzz\Database\DestinationRuleRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\Membership\EntitlementService;
use QRBuzz\QR\Design\BrandKitSettings;
use QRBuzz\Redirect\DestinationResolver;
use QRBuzz\SmartDestinations\DestinationRuleService;
use QRBuzz\SmartDestinations\DestinationSimulationService;
use QRBuzz\SmartDestinations\RuleConditionFormatter;
use QRBuzz\SmartDestinations\RuleConflictAnalyzer;
use QRBuzz\Workspace\WorkspaceService;
use WP_REST_Request;
use WP_REST_Response;

class RestController {

    private WorkspaceService $workspaces;
    private EntitlementService $entitlements;
    private QRRepository $qrCodes;
    private CampaignRepository $campaigns;
    private BrandKitSettings $brandKit;
    private PerQRAnalyticsService $qrAnalytics;
    private CampaignAnalyticsService $campaignAnalytics;
    private QRInsightService $insights;
    private DestinationRuleRepository $rules;
    private DestinationRuleService $ruleService;
    private DestinationSimulationService $simulation;
    private RuleConditionFormatter $formatter;
    private RuleConflictAnalyzer $conflicts;

    public function __construct(?WorkspaceService $workspaces = null, ?EntitlementService $entitlements = null, ?QRRepository $qrCodes = null, ?CampaignRepository $campaigns = null, ?BrandKitSettings $brandKit = null, ?PerQRAnalyticsService $qrAnalytics = null, ?CampaignAnalyticsService $campaignAnalytics = null, ?QRInsightService $insights = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->entitlements = $entitlements ?: new EntitlementService($this->workspaces);
        $this->qrCodes = $qrCodes ?: new QRRepository(null, null, $this->workspaces);
        $this->campaigns = $campaigns ?: new CampaignRepository($this->workspaces);
        $this->brandKit = $brandKit ?: new BrandKitSettings(null, $this->workspaces);
        $this->qrAnalytics = $qrAnalytics ?: new PerQRAnalyticsService(null, $this->workspaces);
        $this->campaignAnalytics = $campaignAnalytics ?: new CampaignAnalyticsService(null, $this->workspaces);
        $this->insights = $insights ?: new QRInsightService($this->qrAnalytics);
        $this->rules = new DestinationRuleRepository($this->workspaces);
        $resolver = new DestinationResolver($this->rules);
        $this->ruleService = new DestinationRuleService($this->rules, $this->qrCodes);
        $this->simulation = new DestinationSimulationService($resolver);
        $this->formatter = new RuleConditionFormatter();
        $this->conflicts = new RuleConflictAnalyzer();
    }

    public function init(): void {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void {
        register_rest_route('qr-buzz/v1', '/workspace', ['methods' => 'GET', 'callback' => [$this, 'workspace'], 'permission_callback' => [$this, 'permission']]);
        register_rest_route('qr-buzz/v1', '/workspace/entitlements', ['methods' => 'GET', 'callback' => [$this, 'entitlements'], 'permission_callback' => [$this, 'permission']]);
        register_rest_route('qr-buzz/v1', '/workspace/usage', ['methods' => 'GET', 'callback' => [$this, 'usage'], 'permission_callback' => [$this, 'permission']]);
        register_rest_route('qr-buzz/v1', '/assets', ['methods' => 'GET', 'callback' => [$this, 'assets'], 'permission_callback' => [$this, 'apiPermission'], 'args' => ['page' => ['sanitize_callback' => 'absint'], 'per_page' => ['sanitize_callback' => 'absint']]]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [$this, 'asset'], 'permission_callback' => [$this, 'apiPermission']]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/analytics', ['methods' => 'GET', 'callback' => [$this, 'assetAnalytics'], 'permission_callback' => [$this, 'apiPermission']]);
        register_rest_route('qr-buzz/v1', '/campaigns', ['methods' => 'GET', 'callback' => [$this, 'campaigns'], 'permission_callback' => [$this, 'apiPermission']]);
        register_rest_route('qr-buzz/v1', '/campaigns/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [$this, 'campaign'], 'permission_callback' => [$this, 'apiPermission']]);
        register_rest_route('qr-buzz/v1', '/campaigns/(?P<id>\d+)/analytics', ['methods' => 'GET', 'callback' => [$this, 'campaignAnalytics'], 'permission_callback' => [$this, 'apiPermission']]);
        register_rest_route('qr-buzz/v1', '/analytics/summary', ['methods' => 'GET', 'callback' => [$this, 'analyticsSummary'], 'permission_callback' => [$this, 'apiPermission']]);
        register_rest_route('qr-buzz/v1', '/brand-kit', ['methods' => 'GET', 'callback' => [$this, 'brandKit'], 'permission_callback' => [$this, 'permission']]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/destination-rules', [['methods' => 'GET', 'callback' => [$this, 'destinationRules'], 'permission_callback' => [$this, 'smartPermission']], ['methods' => 'POST', 'callback' => [$this, 'createDestinationRule'], 'permission_callback' => [$this, 'smartPermission']]]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/destination-rules/(?P<rule_id>\d+)', [['methods' => 'GET', 'callback' => [$this, 'destinationRule'], 'permission_callback' => [$this, 'smartPermission']], ['methods' => 'PATCH', 'callback' => [$this, 'updateDestinationRule'], 'permission_callback' => [$this, 'smartPermission']], ['methods' => 'DELETE', 'callback' => [$this, 'deleteDestinationRule'], 'permission_callback' => [$this, 'smartPermission']]]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/destination-rules/(?P<rule_id>\d+)/duplicate', ['methods' => 'POST', 'callback' => [$this, 'duplicateDestinationRule'], 'permission_callback' => [$this, 'smartPermission']]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/destination-rules/(?P<rule_id>\d+)/toggle', ['methods' => 'POST', 'callback' => [$this, 'toggleDestinationRule'], 'permission_callback' => [$this, 'smartPermission']]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/destination-rules/reorder', ['methods' => 'POST', 'callback' => [$this, 'reorderDestinationRules'], 'permission_callback' => [$this, 'smartPermission']]);
        register_rest_route('qr-buzz/v1', '/assets/(?P<id>\d+)/simulate-destination', ['methods' => 'POST', 'callback' => [$this, 'simulateDestination'], 'permission_callback' => [$this, 'smartPermission']]);
    }

    public function permission(): bool { return current_user_can('manage_options'); }
    public function apiPermission(): bool { return current_user_can('manage_options') && $this->entitlements->allows('api_access'); }
    public function smartPermission(): bool { return is_user_logged_in() && current_user_can('read') && $this->entitlements->allows('smart_destinations'); }

    public function workspace(): WP_REST_Response {
        $workspace = $this->workspaces->current();
        return $this->response(['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'status' => $workspace->status, 'plan_key' => $workspace->planKey]);
    }

    public function entitlements(): WP_REST_Response { return $this->response($this->entitlements->summary()); }
    public function usage(): WP_REST_Response { $summary = $this->entitlements->summary(); return $this->response(['usage' => $summary['usage'], 'limits' => $summary['limits'], 'remaining' => $summary['remaining']]); }

    public function assets(WP_REST_Request $request): WP_REST_Response {
        $page = max(1, absint($request->get_param('page') ?: 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?: 20)));
        $assets = array_map([$this, 'assetData'], $this->qrCodes->queryWithScanCounts('', $page, $perPage));
        return $this->response(['data' => $assets, 'page' => $page, 'per_page' => $perPage]);
    }

    public function asset(WP_REST_Request $request): WP_REST_Response {
        $asset = $this->qrCodes->find(absint($request['id']));
        if (!$asset) { return $this->error('not_found', 'QR asset not found.', 404); }
        return $this->response($this->assetData($asset));
    }

    public function assetAnalytics(WP_REST_Request $request): WP_REST_Response {
        $asset = $this->qrCodes->find(absint($request['id']));
        if (!$asset) { return $this->error('not_found', 'QR asset not found.', 404); }
        if (!$asset->isTrackable()) {
            return $this->response(['asset' => $this->assetData($asset), 'trackable' => false, 'message' => 'Static QR codes do not use QR Buzz tracking.']);
        }
        $range = DateRange::fromRequest($request->get_param('range') ? (string) $request->get_param('range') : '30days');
        return $this->response([
            'asset' => $this->assetData($asset),
            'trackable' => true,
            'range' => ['key' => $range->key, 'label' => $range->label],
            'stats' => $this->qrAnalytics->stats($asset->id),
            'trend' => $this->qrAnalytics->scanCountsByDay($asset->id, $range->daysForTrend()),
            'recent_scans' => $this->scanRows($this->qrAnalytics->recentScans($asset->id, 20)),
            'referrers' => $this->qrAnalytics->referrerBreakdown($asset->id),
            'devices' => $this->qrAnalytics->deviceBreakdown($asset->id),
            'browsers' => $this->qrAnalytics->browserBreakdown($asset->id),
            'insights' => $this->insights->insights($asset),
        ]);
    }

    public function campaigns(): WP_REST_Response { return $this->response(['data' => array_map([$this, 'campaignData'], $this->campaigns->all())]); }
    public function campaign(WP_REST_Request $request): WP_REST_Response { $campaign = $this->campaigns->find(absint($request['id'])); return $campaign ? $this->response($this->campaignData($campaign)) : $this->error('not_found', 'Campaign not found.', 404); }

    public function campaignAnalytics(WP_REST_Request $request): WP_REST_Response {
        $campaign = $this->campaigns->find(absint($request['id']));
        if (!$campaign) { return $this->error('not_found', 'Campaign not found.', 404); }
        $range = DateRange::fromRequest($request->get_param('range') ? (string) $request->get_param('range') : '30days');
        return $this->response([
            'campaign' => $this->campaignData($campaign),
            'range' => ['key' => $range->key, 'label' => $range->label],
            'stats' => $this->campaignAnalytics->stats($campaign->id),
            'trend' => $this->campaignAnalytics->scanCountsByDay($campaign->id, $range),
            'top_qr_codes' => $this->campaignAnalytics->topQrCodes($campaign->id, $range, 10),
            'recent_scans' => $this->scanRows($this->campaignAnalytics->recentScans($campaign->id, $range, 20)),
            'referrers' => $this->campaignAnalytics->referrerBreakdown($campaign->id, $range),
            'devices' => $this->campaignAnalytics->deviceBreakdown($campaign->id, $range),
            'browsers' => $this->campaignAnalytics->browserBreakdown($campaign->id, $range),
        ]);
    }

    public function analyticsSummary(): WP_REST_Response { return $this->response($this->qrCodes->dashboardSummary()); }
    public function brandKit(): WP_REST_Response { return $this->response($this->brandKit->get()); }

    public function destinationRules(WP_REST_Request $request): WP_REST_Response { $qr = $this->dynamicAsset($request); if (!$qr) { return $this->error('not_found', 'Dynamic QR asset not found.', 404); } $rules = $this->rules->forQrCode($qr->id); return $this->response(['rules' => array_map([$this, 'ruleData'], $rules), 'warnings' => $this->conflicts->analyze($qr, $rules)]); }
    public function destinationRule(WP_REST_Request $request): WP_REST_Response { $rule = $this->requestedRule($request); return $rule ? $this->response($this->ruleData($rule)) : $this->error('not_found', 'Smart Destination rule not found.', 404); }
    public function createDestinationRule(WP_REST_Request $request): WP_REST_Response { $qr = $this->dynamicAsset($request); if (!$qr) { return $this->error('not_found', 'Dynamic QR asset not found.', 404); } if (!$this->entitlements->canCreateSmartRule($qr->id)) { return $this->error('limit_reached', 'Smart Destination rule limit reached.', 403); } $result = $this->ruleService->save($qr->id, $request->get_json_params() ?: $request->get_params(), get_current_user_id()); return $result['id'] ? $this->response($this->ruleData($this->rules->find($result['id'])), 201) : $this->response(['errors' => $result['errors']], 422); }
    public function updateDestinationRule(WP_REST_Request $request): WP_REST_Response { $rule = $this->requestedRule($request); if (!$rule) { return $this->error('not_found', 'Smart Destination rule not found.', 404); } $input = array_merge($this->ruleInput($rule), $request->get_json_params() ?: []); $result = $this->ruleService->save($rule->qrId, $input, get_current_user_id(), $rule->id); return $result['id'] ? $this->response($this->ruleData($this->rules->find($rule->id))) : $this->response(['errors' => $result['errors']], 422); }
    public function deleteDestinationRule(WP_REST_Request $request): WP_REST_Response { $rule = $this->requestedRule($request); if (!$rule) { return $this->error('not_found', 'Smart Destination rule not found.', 404); } return $this->ruleService->delete($rule->qrId, $rule->id, get_current_user_id()) ? $this->response(['deleted' => true]) : $this->error('delete_failed', 'Rule could not be deleted.', 500); }
    public function duplicateDestinationRule(WP_REST_Request $request): WP_REST_Response { $rule = $this->requestedRule($request); if (!$rule) { return $this->error('not_found', 'Smart Destination rule not found.', 404); } if (!$this->entitlements->canCreateSmartRule($rule->qrId)) { return $this->error('limit_reached', 'Smart Destination rule limit reached.', 403); } $id = $this->ruleService->duplicate($rule->qrId, $rule->id, get_current_user_id()); return $id ? $this->response($this->ruleData($this->rules->find($id)), 201) : $this->error('duplicate_failed', 'Rule could not be duplicated.', 500); }
    public function toggleDestinationRule(WP_REST_Request $request): WP_REST_Response { $rule = $this->requestedRule($request); if (!$rule) { return $this->error('not_found', 'Smart Destination rule not found.', 404); } $this->ruleService->toggle($rule->qrId, $rule->id, get_current_user_id()); return $this->response($this->ruleData($this->rules->find($rule->id))); }
    public function reorderDestinationRules(WP_REST_Request $request): WP_REST_Response { $qr = $this->dynamicAsset($request); if (!$qr) { return $this->error('not_found', 'Dynamic QR asset not found.', 404); } $ids = (array) $request->get_param('rule_ids'); if (!$this->rules->reorder($qr->id, $ids, get_current_user_id())) { return $this->error('invalid_order', 'The order must contain every rule exactly once.', 422); } return $this->destinationRules($request); }
    public function simulateDestination(WP_REST_Request $request): WP_REST_Response { $qr = $this->dynamicAsset($request); if (!$qr) { return $this->error('not_found', 'Dynamic QR asset not found.', 404); } try { $result = $this->simulation->simulate($qr, $request->get_param('datetime') ? (string) $request->get_param('datetime') : null); } catch (\InvalidArgumentException $exception) { return $this->error('invalid_datetime', $exception->getMessage(), 422); } $resolution = $result['resolution']; return $this->response(['tested_at' => $result['tested_at'], 'timezone' => $result['timezone'], 'status' => $qr->effectiveStatus($result['timestamp']), 'reason' => $resolution->reason, 'destination_url' => $resolution->destinationUrl, 'message' => $resolution->message, 'matched_rule_id' => $resolution->matchedRuleId, 'matched_rule_name' => $resolution->matchedRuleName, 'simulation_only' => true]); }

    private function assetData($asset): array {
        return ['id' => $asset->id, 'workspace_id' => $asset->workspaceId, 'name' => $asset->name, 'type' => $asset->type, 'shortcode' => $asset->shortcode, 'destination_url' => $asset->destinationUrl, 'is_trackable' => $asset->isTrackable(), 'campaign_id' => $asset->campaignId, 'campaign_name' => $asset->campaignName, 'scan_count' => $asset->scanCount, 'created_at' => $asset->createdAt, 'updated_at' => $asset->updatedAt];
    }

    private function campaignData($campaign): array {
        return ['id' => $campaign->id, 'workspace_id' => $campaign->workspaceId, 'name' => $campaign->name, 'slug' => $campaign->slug, 'status' => $campaign->status, 'qr_count' => $campaign->qrCount, 'dynamic_count' => $campaign->dynamicCount, 'static_count' => $campaign->staticCount, 'scan_count' => $campaign->scanCount];
    }

    private function dynamicAsset(WP_REST_Request $request) { $qr = $this->qrCodes->find(absint($request['id'])); return $qr && $qr->isTrackable() ? $qr : null; }
    private function requestedRule(WP_REST_Request $request) { $qr = $this->dynamicAsset($request); return $qr ? $this->rules->findForQrCode(absint($request['rule_id']), $qr->id) : null; }
    private function ruleData($rule): array { return ['id' => $rule->id, 'qr_id' => $rule->qrId, 'name' => $rule->name, 'priority' => $rule->priority, 'status' => $rule->status, 'destination_url' => $rule->destinationUrl, 'starts_at' => $rule->startsAt, 'ends_at' => $rule->endsAt, 'days_of_week' => $rule->dayNumbers(), 'time_start' => $rule->timeStart, 'time_end' => $rule->timeEnd, 'condition_summary' => $this->formatter->format($rule), 'created_at' => $rule->createdAt, 'updated_at' => $rule->updatedAt]; }
    private function ruleInput($rule): array { return ['name' => $rule->name, 'priority' => $rule->priority, 'status' => $rule->status, 'destination_url' => $rule->destinationUrl, 'starts_at' => $rule->startsAt ? \QRBuzz\Utils\DateTimeHelper::utcToLocalInput($rule->startsAt) : '', 'ends_at' => $rule->endsAt ? \QRBuzz\Utils\DateTimeHelper::utcToLocalInput($rule->endsAt) : '', 'days_of_week' => $rule->dayNumbers(), 'time_start' => $rule->timeStart, 'time_end' => $rule->timeEnd]; }

    private function scanRows(array $rows): array {
        return array_map(static fn($row): array => [
            'id' => (int) $row->id,
            'qr_id' => (int) $row->qr_id,
            'scanned_at' => (string) $row->scanned_at,
            'referrer' => (string) $row->referrer,
            'country' => $row->country ? (string) $row->country : null,
            'user_agent_summary' => isset($row->user_agent_summary) ? (string) $row->user_agent_summary : '',
            'resolution_reason' => isset($row->resolution_reason) ? (string) $row->resolution_reason : '',
            'scan_status' => isset($row->scan_status) ? (string) $row->scan_status : '',
        ], $rows);
    }

    private function response(array $data, int $status = 200): WP_REST_Response { return new WP_REST_Response(['version' => '1', 'workspace_id' => $this->workspaces->id(), 'data' => $data], $status); }
    private function error(string $code, string $message, int $status): WP_REST_Response { return new WP_REST_Response(['error' => ['code' => $code, 'message' => $message]], $status); }
}
