<?php
namespace QRBuzz\Platform;

use QRBuzz\Database\Schema;

class PlatformErrorRepository {

    public function record(string $category, string $message, array $context = [], string $severity = 'warning'): void {
        global $wpdb;
        $summary = substr(sanitize_text_field($message), 0, 500);
        $fingerprint = hash('sha256', sanitize_key($category) . '|' . $summary . '|' . (string) ($context['workspace_id'] ?? '') . '|' . (string) ($context['qr_id'] ?? ''));
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::applicationErrorsTable() . ' WHERE fingerprint = %s LIMIT 1', $fingerprint));
        $now = current_time('mysql');
        $row = [
            'severity' => sanitize_key($severity) ?: 'warning',
            'category' => sanitize_key($category) ?: 'unknown',
            'message_summary' => $summary,
            'sanitized_context' => wp_json_encode($this->redact($context)),
            'workspace_id' => absint($context['workspace_id'] ?? 0) ?: null,
            'user_id' => absint($context['user_id'] ?? 0) ?: null,
            'qr_id' => absint($context['qr_id'] ?? 0) ?: null,
            'campaign_id' => absint($context['campaign_id'] ?? 0) ?: null,
            'last_seen_at' => $now,
        ];
        if ($existing) {
            $row['occurrence_count'] = ((int) $existing->occurrence_count) + 1;
            if ((string) $existing->status === 'resolved') { $row['status'] = 'new'; $row['resolved_at'] = null; }
            $formats = ['%s','%s','%s','%s','%d','%d','%d','%d','%s','%d'];
            if (array_key_exists('status', $row)) { $formats[] = '%s'; $formats[] = '%s'; }
            $wpdb->update(Schema::applicationErrorsTable(), $row, ['id' => (int) $existing->id], $formats, ['%d']);
            return;
        }
        $row['fingerprint'] = $fingerprint;
        $row['occurrence_count'] = 1;
        $row['status'] = 'new';
        $row['first_seen_at'] = $now;
        $wpdb->insert(Schema::applicationErrorsTable(), $row, ['%s','%s','%s','%s','%d','%d','%d','%d','%s','%s','%d','%s','%s']);
    }

    public function list(string $status = '', string $search = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        [$where, $params] = $this->where($status, $search);
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare('SELECT e.*, w.name AS workspace_name, u.display_name AS user_name FROM ' . Schema::applicationErrorsTable() . ' e LEFT JOIN ' . Schema::workspacesTable() . ' w ON w.id = e.workspace_id LEFT JOIN ' . $wpdb->users . ' u ON u.ID = e.user_id ' . $where . ' ORDER BY e.last_seen_at DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
    }

    public function count(string $status = '', string $search = ''): int {
        global $wpdb;
        [$where, $params] = $this->where($status, $search);
        return (int) ($params ? $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::applicationErrorsTable() . ' e ' . $where, ...$params)) : $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::applicationErrorsTable() . ' e ' . $where));
    }

    public function updateStatus(int $id, string $status, string $notes = ''): bool {
        global $wpdb;
        $status = in_array($status, ['new', 'investigating', 'resolved', 'ignored'], true) ? $status : 'new';
        $data = ['status' => $status, 'notes' => sanitize_textarea_field($notes)];
        $formats = ['%s','%s'];
        if ($status === 'resolved') { $data['resolved_at'] = current_time('mysql'); $formats[] = '%s'; }
        return $wpdb->update(Schema::applicationErrorsTable(), $data, ['id' => $id], $formats, ['%d']) !== false;
    }

    private function where(string $status, string $search): array {
        global $wpdb;
        $where = ['1=1']; $params = [];
        if ($status !== '') { $where[] = 'e.status = %s'; $params[] = sanitize_key($status); }
        if ($search !== '') { $where[] = '(e.category LIKE %s OR e.message_summary LIKE %s)'; $like = '%' . $wpdb->esc_like($search) . '%'; $params[] = $like; $params[] = $like; }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function redact(array $context): array {
        foreach ($context as $key => $value) {
            if (preg_match('/secret|password|token|signature|key|authorization|path/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }
        return $context;
    }
}
