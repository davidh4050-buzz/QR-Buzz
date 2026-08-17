<?php
namespace QRBuzz\Platform;

use QRBuzz\Database\Schema;

class PlatformAuditRepository {

    public function record(string $eventType, string $description, array $data = []): void {
        global $wpdb;
        $wpdb->insert(Schema::auditEventsTable(), [
            'event_type' => sanitize_key($eventType),
            'actor_id' => absint($data['actor_id'] ?? get_current_user_id()) ?: null,
            'actor_type' => sanitize_key((string) ($data['actor_type'] ?? 'administrator')),
            'workspace_id' => absint($data['workspace_id'] ?? 0) ?: null,
            'user_id' => absint($data['user_id'] ?? 0) ?: null,
            'entity_type' => isset($data['entity_type']) ? sanitize_key((string) $data['entity_type']) : null,
            'entity_id' => absint($data['entity_id'] ?? 0) ?: null,
            'description' => sanitize_text_field($description),
            'before_data' => isset($data['before']) ? wp_json_encode($this->redact((array) $data['before'])) : null,
            'after_data' => isset($data['after']) ? wp_json_encode($this->redact((array) $data['after'])) : null,
            'metadata' => isset($data['metadata']) ? wp_json_encode($this->redact((array) $data['metadata'])) : null,
            'correlation_id' => isset($data['correlation_id']) ? sanitize_text_field((string) $data['correlation_id']) : null,
            'created_at' => current_time('mysql'),
        ], ['%s','%d','%s','%d','%d','%s','%d','%s','%s','%s','%s','%s','%s']);
    }

    public function list(string $search = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        $where = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (event_type LIKE %s OR description LIKE %s OR entity_type LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params = [$like, $like, $like];
        }
        $params[] = $perPage;
        $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare('SELECT a.*, u.display_name AS actor_name FROM ' . Schema::auditEventsTable() . ' a LEFT JOIN ' . $wpdb->users . ' u ON u.ID = a.actor_id ' . $where . ' ORDER BY a.created_at DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
    }

    public function count(string $search = ''): int {
        global $wpdb;
        if ($search === '') { return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::auditEventsTable()); }
        $like = '%' . $wpdb->esc_like($search) . '%';
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::auditEventsTable() . ' WHERE event_type LIKE %s OR description LIKE %s OR entity_type LIKE %s', $like, $like, $like));
    }

    private function redact(array $data): array {
        foreach ($data as $key => $value) {
            if (preg_match('/secret|password|token|signature|key|authorization/i', (string) $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }
        return $data;
    }
}
