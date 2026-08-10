<?php
namespace QRBuzz\Platform;

use QRBuzz\Database\Schema;

class WebhookLogRepository {

    public function received(string $provider, string $eventId, string $eventType, array $metadata = []): void {
        global $wpdb;
        $now = current_time('mysql');
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::webhookLogsTable() . ' WHERE provider = %s AND event_id = %s LIMIT 1', sanitize_key($provider), sanitize_text_field($eventId)));
        $row = [
            'provider' => sanitize_key($provider),
            'event_id' => sanitize_text_field($eventId),
            'event_type' => sanitize_text_field($eventType),
            'status' => 'received',
            'workspace_id' => absint($metadata['workspace_id'] ?? 0) ?: null,
            'subscription_id' => absint($metadata['subscription_id'] ?? 0) ?: null,
            'metadata' => wp_json_encode($this->redact($metadata)),
        ];
        if ($existing) {
            $wpdb->update(Schema::webhookLogsTable(), $row, ['id' => (int) $existing->id], ['%s','%s','%s','%s','%d','%d','%s'], ['%d']);
            return;
        }
        $row['received_at'] = $now;
        $wpdb->insert(Schema::webhookLogsTable(), $row, ['%s','%s','%s','%s','%d','%d','%s','%s']);
    }

    public function processed(string $provider, string $eventId, string $status = 'processed', string $error = ''): void {
        global $wpdb;
        $wpdb->update(Schema::webhookLogsTable(), ['status' => sanitize_key($status), 'processed_at' => current_time('mysql'), 'error_summary' => $error ? sanitize_text_field($error) : null], ['provider' => sanitize_key($provider), 'event_id' => sanitize_text_field($eventId)], ['%s','%s','%s'], ['%s','%s']);
    }

    public function retry(int $id): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('UPDATE ' . Schema::webhookLogsTable() . " SET retry_count = retry_count + 1, status = 'retrying' WHERE id = %d", $id)) !== false;
    }

    public function list(string $status = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        $where = 'WHERE 1=1'; $params = [];
        if ($status !== '') { $where .= ' AND wl.status = %s'; $params[] = sanitize_key($status); }
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare('SELECT wl.*, w.name AS workspace_name FROM ' . Schema::webhookLogsTable() . ' wl LEFT JOIN ' . Schema::workspacesTable() . ' w ON w.id = wl.workspace_id ' . $where . ' ORDER BY wl.received_at DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
    }

    public function count(string $status = ''): int {
        global $wpdb;
        if ($status === '') { return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::webhookLogsTable()); }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::webhookLogsTable() . ' WHERE status = %s', sanitize_key($status)));
    }

    private function redact(array $metadata): array {
        foreach ($metadata as $key => $value) {
            if (preg_match('/secret|password|token|signature|key|authorization/i', (string) $key)) {
                $metadata[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $metadata[$key] = $this->redact($value);
            }
        }
        return $metadata;
    }
}
