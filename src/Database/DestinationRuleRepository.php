<?php
namespace QRBuzz\Database;

use QRBuzz\Models\DestinationRule;
use QRBuzz\Platform\PlatformAuditRepository;
use QRBuzz\SmartDestinations\RuleConditionFormatter;
use QRBuzz\Workspace\WorkspaceService;

class DestinationRuleRepository {

    private WorkspaceService $workspaces;
    private PlatformAuditRepository $audit;
    private RuleConditionFormatter $formatter;

    public function __construct(?WorkspaceService $workspaces = null, ?PlatformAuditRepository $audit = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->audit = $audit ?: new PlatformAuditRepository();
        $this->formatter = new RuleConditionFormatter();
    }

    /** @return DestinationRule[] */
    public function forQrCode(int $qrId, bool $activeOnly = false): array {
        global $wpdb;
        $where = 'WHERE qr_id = %d AND workspace_id = %d';
        $params = [$qrId, $this->workspaceId()];
        if ($activeOnly) { $where .= " AND status = 'active'"; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::destinationRulesTable() . " {$where} ORDER BY priority ASC, id ASC", ...$params)) ?: [];
        return array_map([DestinationRule::class, 'fromRow'], $rows);
    }

    public function find(int $id): ?DestinationRule {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::destinationRulesTable() . ' WHERE id = %d AND workspace_id = %d LIMIT 1', $id, $this->workspaceId()));
        return $row ? DestinationRule::fromRow($row) : null;
    }

    public function findForQrCode(int $id, int $qrId): ?DestinationRule {
        $rule = $this->find($id);
        return $rule && $rule->qrId === $qrId ? $rule : null;
    }

    public function create(int $qrId, array $data, int $userId = 0): int {
        global $wpdb;
        $now = current_time('mysql');
        $row = $this->row($qrId, $data, $now, $now);
        $wpdb->insert(Schema::destinationRulesTable(), $row, ['%d','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
        $id = (int) $wpdb->insert_id;
        $this->recordHistory($qrId, 'rule_created', '', $row['name'], $userId);
        if ($id > 0) { $this->recordAudit('rule_created', $id, $qrId, 'Created Smart Destination rule “' . $row['name'] . '”.', [], $row, $userId); }
        $this->normalizePriorities($qrId, $userId, false);
        return $id;
    }

    public function update(int $id, array $data, int $userId = 0): bool {
        global $wpdb;
        $before = $this->find($id);
        if (!$before) { return false; }
        $row = $this->row($before->qrId, $data, $before->createdAt, current_time('mysql'));
        unset($row['workspace_id'], $row['qr_id'], $row['created_at']);
        $result = $wpdb->update(Schema::destinationRulesTable(), $row, ['id' => $id, 'workspace_id' => $this->workspaceId()], ['%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'], ['%d', '%d']);
        if ($result === false) { return false; }
        $after = $this->find($id);
        $this->recordHistory($before->qrId, 'rule_updated', $this->summary($before), $after ? $this->summary($after) : $row['name'], $userId);
        $this->recordAudit('rule_updated', $id, $before->qrId, 'Updated Smart Destination rule “' . $row['name'] . '”.', $this->snapshot($before), $after ? $this->snapshot($after) : $row, $userId);
        $this->normalizePriorities($before->qrId, $userId, false);
        return true;
    }

    public function delete(int $id, int $userId = 0): bool {
        global $wpdb;
        $rule = $this->find($id);
        if (!$rule) { return false; }
        $result = $wpdb->delete(Schema::destinationRulesTable(), ['id' => $id, 'workspace_id' => $this->workspaceId()], ['%d', '%d']);
        if ($result === false) { return false; }
        $this->recordHistory($rule->qrId, 'rule_deleted', $rule->name, '', $userId);
        $this->recordAudit('rule_deleted', $id, $rule->qrId, 'Deleted Smart Destination rule “' . $rule->name . '”.', $this->snapshot($rule), [], $userId);
        $this->normalizePriorities($rule->qrId, $userId, false);
        return true;
    }

    public function setStatus(int $id, string $status, int $userId = 0): bool {
        global $wpdb;
        $rule = $this->find($id);
        if (!$rule) { return false; }
        $status = $status === 'inactive' ? 'inactive' : 'active';
        $result = $wpdb->update(Schema::destinationRulesTable(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id, 'workspace_id' => $this->workspaceId()], ['%s', '%s'], ['%d', '%d']);
        if ($result === false) { return false; }
        $this->recordHistory($rule->qrId, $status === 'active' ? 'rule_activated' : 'rule_deactivated', $rule->status, $status, $userId);
        $this->recordAudit('rule_' . ($status === 'active' ? 'activated' : 'deactivated'), $id, $rule->qrId, ($status === 'active' ? 'Activated' : 'Deactivated') . ' Smart Destination rule “' . $rule->name . '”.', ['status' => $rule->status], ['status' => $status], $userId);
        return true;
    }

    public function duplicate(int $id, int $userId = 0): int {
        $source = $this->find($id);
        if (!$source) { return 0; }
        $newId = $this->create($source->qrId, ['name' => $source->name . ' Copy', 'priority' => $source->priority + 1, 'status' => 'inactive', 'destination_url' => $source->destinationUrl, 'starts_at' => $source->startsAt, 'ends_at' => $source->endsAt, 'days_of_week' => $source->dayNumbers(), 'time_start' => $source->timeStart, 'time_end' => $source->timeEnd, 'conditions' => $source->conditions], $userId);
        if ($newId > 0) {
            $this->recordHistory($source->qrId, 'rule_duplicated', $source->name, $source->name . ' Copy', $userId);
            $this->recordAudit('rule_duplicated', $newId, $source->qrId, 'Duplicated Smart Destination rule “' . $source->name . '” as inactive.', ['source_rule_id' => $source->id], ['new_rule_id' => $newId], $userId);
        }
        return $newId;
    }

    public function move(int $id, string $direction, int $userId = 0): bool {
        $rule = $this->find($id);
        if (!$rule || !in_array($direction, ['up','down'], true)) { return false; }
        $rules = $this->forQrCode($rule->qrId);
        $index = array_search($id, array_map(static fn(DestinationRule $item): int => $item->id, $rules), true);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === false || !isset($rules[$swap])) { return true; }
        [$rules[$index], $rules[$swap]] = [$rules[$swap], $rules[$index]];
        $before = $rule->priority;
        $this->storeOrder($rules);
        $after = $this->find($id);
        $this->recordHistory($rule->qrId, 'rule_reordered', $rule->name . ': priority ' . $before, $rule->name . ': priority ' . ($after ? $after->priority : $swap + 1), $userId);
        $this->recordAudit('rule_reordered', $id, $rule->qrId, 'Moved Smart Destination rule “' . $rule->name . '” ' . $direction . '.', ['priority' => $before], ['priority' => $after ? $after->priority : $swap + 1], $userId);
        return true;
    }

    public function reorder(int $qrId, array $ids, int $userId = 0): bool {
        $rules = $this->forQrCode($qrId);
        $known = array_map(static fn(DestinationRule $rule): int => $rule->id, $rules);
        $ids = array_values(array_unique(array_map('absint', $ids)));
        if (count($ids) !== count($known) || array_diff($ids, $known) || array_diff($known, $ids)) { return false; }
        $map = []; foreach ($rules as $rule) { $map[$rule->id] = $rule; }
        $this->storeOrder(array_map(static fn(int $id): DestinationRule => $map[$id], $ids));
        $this->recordHistory($qrId, 'rules_reordered', implode(',', $known), implode(',', $ids), $userId);
        return true;
    }

    public function normalizePriorities(int $qrId, int $userId = 0, bool $record = true): void {
        $rules = $this->forQrCode($qrId);
        $changed = false; foreach ($rules as $index => $rule) { if ($rule->priority !== $index + 1) { $changed = true; break; } }
        if (!$changed) { return; }
        $this->storeOrder($rules);
        if ($record) { $this->recordHistory($qrId, 'rules_reordered', 'Unnormalised priorities', 'Priorities normalised', $userId); }
    }

    public function migrateLegacyScheduledDestinations(): void {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $rulesTable = Schema::destinationRulesTable();
        $rows = $wpdb->get_results("SELECT id, workspace_id, scheduled_url, scheduled_start_at, scheduled_end_at FROM {$qrcodesTable} WHERE is_trackable = 1 AND scheduled_url IS NOT NULL AND scheduled_url != '' AND scheduled_start_at IS NOT NULL AND scheduled_end_at IS NOT NULL") ?: [];
        foreach ($rows as $row) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rulesTable} WHERE qr_id = %d AND conditions_json LIKE %s", (int) $row->id, '%legacy_scheduled%'));
            if ($exists > 0) { continue; }
            $this->create((int) $row->id, ['workspace_id' => (int) $row->workspace_id, 'name' => 'Scheduled destination', 'priority' => 10, 'status' => 'active', 'destination_url' => (string) $row->scheduled_url, 'starts_at' => (string) $row->scheduled_start_at, 'ends_at' => (string) $row->scheduled_end_at, 'days_of_week' => '', 'time_start' => '', 'time_end' => '', 'conditions' => ['legacy_scheduled' => true]]);
        }
    }

    private function row(int $qrId, array $data, string $createdAt, string $updatedAt): array {
        $days = isset($data['days_of_week']) && is_array($data['days_of_week']) ? implode(',', array_values(array_filter(array_map('absint', $data['days_of_week']), static fn(int $day): bool => $day >= 1 && $day <= 7))) : trim((string) ($data['days_of_week'] ?? ''));
        $conditions = is_array($data['conditions'] ?? null) ? $data['conditions'] : [];
        $conditions['date_range'] = !empty($data['starts_at']) || !empty($data['ends_at']);
        $conditions['days_of_week'] = $days !== '';
        $conditions['time_of_day'] = !empty($data['time_start']) || !empty($data['time_end']);
        return ['workspace_id' => absint($data['workspace_id'] ?? $this->workspaceId()), 'qr_id' => $qrId, 'name' => sanitize_text_field((string) ($data['name'] ?? 'Smart destination')), 'priority' => max(1, absint($data['priority'] ?? 10)), 'status' => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active', 'destination_url' => esc_url_raw((string) ($data['destination_url'] ?? '')), 'conditions_json' => wp_json_encode($conditions), 'starts_at' => $this->nullableString($data['starts_at'] ?? null), 'ends_at' => $this->nullableString($data['ends_at'] ?? null), 'days_of_week' => $days === '' ? null : $days, 'time_start' => $this->nullableTime($data['time_start'] ?? null), 'time_end' => $this->nullableTime($data['time_end'] ?? null), 'created_at' => $createdAt, 'updated_at' => $updatedAt];
    }

    private function recordHistory(int $qrId, string $changeType, $previous, $new, int $userId): void { global $wpdb; $wpdb->insert(Schema::destinationHistoryTable(), ['qr_id' => $qrId, 'change_type' => $changeType, 'previous_value' => $previous === null ? null : (string) $previous, 'new_value' => $new === null ? null : (string) $new, 'changed_by' => $userId > 0 ? $userId : null, 'changed_at' => current_time('mysql')], ['%d', '%s', '%s', '%s', '%d', '%s']); }
    private function storeOrder(array $rules): void { global $wpdb; foreach (array_values($rules) as $index => $rule) { $wpdb->update(Schema::destinationRulesTable(), ['priority' => $index + 1, 'updated_at' => current_time('mysql')], ['id' => $rule->id, 'workspace_id' => $this->workspaceId(), 'qr_id' => $rule->qrId], ['%d','%s'], ['%d','%d','%d']); } }
    private function summary(DestinationRule $rule): string { return $rule->name . ' — ' . $this->formatter->format($rule) . ' → ' . $rule->destinationUrl; }
    private function snapshot(DestinationRule $rule): array { return ['name' => $rule->name, 'status' => $rule->status, 'priority' => $rule->priority, 'destination' => $rule->destinationUrl, 'conditions' => $this->formatter->format($rule)]; }
    private function recordAudit(string $event, int $ruleId, int $qrId, string $description, array $before, array $after, int $userId): void { $this->audit->record($event, $description, ['actor_id' => $userId ?: get_current_user_id(), 'workspace_id' => $this->workspaceId(), 'entity_type' => 'destination_rule', 'entity_id' => $ruleId, 'before' => $before, 'after' => $after, 'metadata' => ['qr_id' => $qrId]]); }
    private function nullableString($value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function nullableTime($value): ?string { $value = trim((string) $value); return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) ? $value : null; }
    private function workspaceId(): int { return $this->workspaces->id(); }
}
