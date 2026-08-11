<?php
namespace QRBuzz\Platform;

use QRBuzz\Database\Schema;

class PlatformEventRepository {

    public function record(string $eventName, array $data = []): void {
        global $wpdb;
        $wpdb->insert(Schema::platformEventsTable(), [
            'event_name' => sanitize_key($eventName),
            'user_id' => absint($data['user_id'] ?? 0) ?: null,
            'workspace_id' => absint($data['workspace_id'] ?? 0) ?: null,
            'object_type' => isset($data['object_type']) ? sanitize_key((string) $data['object_type']) : null,
            'object_id' => absint($data['object_id'] ?? 0) ?: null,
            'metadata' => isset($data['metadata']) ? wp_json_encode($this->redact((array) $data['metadata'])) : null,
            'correlation_id' => isset($data['correlation_id']) ? sanitize_text_field((string) $data['correlation_id']) : null,
            'occurred_at' => current_time('mysql'),
        ], ['%s','%d','%d','%s','%d','%s','%s','%s']);
    }

    public function recent(int $limit = 20): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT e.*, w.name AS workspace_name, u.display_name AS user_name FROM ' . Schema::platformEventsTable() . ' e LEFT JOIN ' . Schema::workspacesTable() . ' w ON w.id = e.workspace_id LEFT JOIN ' . $wpdb->users . ' u ON u.ID = e.user_id ORDER BY e.occurred_at DESC LIMIT %d', $limit)) ?: [];
    }

    public function countSince(string $eventName, string $since): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::platformEventsTable() . ' WHERE event_name = %s AND occurred_at >= %s', sanitize_key($eventName), $since));
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
