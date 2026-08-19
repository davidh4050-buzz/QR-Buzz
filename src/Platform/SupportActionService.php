<?php
namespace QRBuzz\Platform;

use QRBuzz\Account\AuthService;
use QRBuzz\Account\ProfileRepository;
use QRBuzz\Billing\SubscriptionRepository;
use QRBuzz\Database\Schema;
use QRBuzz\Database\WorkspaceRepository;

class SupportActionService {

    private PlatformAuditRepository $audit;
    private ProfileRepository $profiles;
    private WorkspaceRepository $workspaces;
    private SubscriptionRepository $subscriptions;
    private PlatformErrorRepository $errors;
    private WebhookLogRepository $webhooks;

    public function __construct(?PlatformAuditRepository $audit = null, ?ProfileRepository $profiles = null, ?WorkspaceRepository $workspaces = null, ?SubscriptionRepository $subscriptions = null, ?PlatformErrorRepository $errors = null, ?WebhookLogRepository $webhooks = null) {
        $this->audit = $audit ?: new PlatformAuditRepository();
        $this->profiles = $profiles ?: new ProfileRepository();
        $this->workspaces = $workspaces ?: new WorkspaceRepository();
        $this->subscriptions = $subscriptions ?: new SubscriptionRepository();
        $this->errors = $errors ?: new PlatformErrorRepository();
        $this->webhooks = $webhooks ?: new WebhookLogRepository();
    }

    public function resendVerification(int $userId): bool {
        $user = get_user_by('id', $userId);
        if (!$user) { return false; }
        (new AuthService($this->profiles, $this->workspaces, $this->subscriptions))->sendVerification($userId);
        $this->audit->record('administrator_action', 'Verification email resent.', ['user_id' => $userId, 'entity_type' => 'user', 'entity_id' => $userId]);
        return true;
    }

    public function resetUserOnboarding(int $userId): bool {
        $this->profiles->updateOnboarding($userId, 'pending', 'plan');
        $workspace = $this->workspaces->forUser($userId);
        if ($workspace) { $this->workspaces->updateOnboarding($workspace->id, 'pending', 'plan'); }
        $this->audit->record('administrator_action', 'User onboarding reset.', ['user_id' => $userId, 'workspace_id' => $workspace ? $workspace->id : 0, 'entity_type' => 'user', 'entity_id' => $userId]);
        return true;
    }

    public function suspendWorkspace(int $workspaceId): bool { return $this->setWorkspaceStatus($workspaceId, 'suspended'); }
    public function reactivateWorkspace(int $workspaceId): bool { return $this->setWorkspaceStatus($workspaceId, 'active'); }

    public function assignTestPlan(int $workspaceId, string $planKey): bool {
        $planKey = in_array($planKey, ['free', 'pro', 'business'], true) ? $planKey : 'free';
        $before = $this->workspaces->find($workspaceId);
        if (!$before) { return false; }
        $subscription = $this->subscriptions->forWorkspace($workspaceId);
        if ($subscription && (!empty($subscription->stripe_customer_id) || !empty($subscription->stripe_subscription_id))) { return false; }
        $result = $this->workspaces->updatePlan($workspaceId, $planKey);
        $this->subscriptions->upsert($workspaceId, ['plan_key' => $planKey, 'status' => $planKey === 'free' ? 'free' : 'active']);
        if ($result) { $this->audit->record('plan_changed', 'Administrator assigned a test plan.', ['workspace_id' => $workspaceId, 'entity_type' => 'workspace', 'entity_id' => $workspaceId, 'before' => ['plan_key' => $before->planKey], 'after' => ['plan_key' => $planKey]]); }
        return $result;
    }

    public function updateErrorStatus(int $errorId, string $status, string $notes = ''): bool {
        $result = $this->errors->updateStatus($errorId, $status, $notes);
        if ($result) { $this->audit->record('administrator_action', 'Application error marked ' . $status . '.', ['entity_type' => 'application_error', 'entity_id' => $errorId]); }
        return $result;
    }

    public function retryWebhook(int $webhookId): bool {
        $result = $this->webhooks->retry($webhookId);
        if ($result) { $this->audit->record('webhook_manually_retried', 'Webhook marked for retry review.', ['entity_type' => 'webhook', 'entity_id' => $webhookId]); }
        return $result;
    }

    public function diagnosticSnapshot(int $workspaceId, array $summary): bool {
        global $wpdb;
        $result = $wpdb->insert(Schema::diagnosticSnapshotsTable(), ['workspace_id' => $workspaceId ?: null, 'snapshot_type' => $workspaceId > 0 ? 'workspace' : 'platform', 'summary' => wp_json_encode($this->redact($summary)), 'created_by' => get_current_user_id() ?: null, 'created_at' => current_time('mysql')], ['%d','%s','%s','%d','%s']);
        if ($result !== false) { $this->audit->record('administrator_action', 'Diagnostics snapshot generated.', ['workspace_id' => $workspaceId, 'entity_type' => $workspaceId > 0 ? 'workspace' : 'platform', 'entity_id' => $workspaceId]); }
        return $result !== false;
    }

    private function setWorkspaceStatus(int $workspaceId, string $status): bool {
        global $wpdb;
        $before = $this->workspaces->find($workspaceId);
        if (!$before) { return false; }
        $status = $status === 'suspended' ? 'suspended' : 'active';
        $result = $wpdb->update(Schema::workspacesTable(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $workspaceId], ['%s','%s'], ['%d']);
        if ($result !== false) { $this->audit->record($status === 'suspended' ? 'workspace_suspended' : 'workspace_reactivated', 'Workspace status changed by administrator.', ['workspace_id' => $workspaceId, 'entity_type' => 'workspace', 'entity_id' => $workspaceId, 'before' => ['status' => $before->status], 'after' => ['status' => $status]]); }
        return $result !== false;
    }

    private function redact(array $data): array {
        foreach ($data as $key => $value) {
            if (preg_match('/secret|password|token|signature|key|authorization/i', (string) $key)) { $data[$key] = '[redacted]'; }
            elseif (is_array($value)) { $data[$key] = $this->redact($value); }
        }
        return $data;
    }
}
