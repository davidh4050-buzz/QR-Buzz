<?php
namespace QRBuzz\Database;

use QRBuzz\Models\Workspace;

class WorkspaceRepository {

    public function default(): Workspace { $workspace = $this->firstActive(); if ($workspace) { return $workspace; } $id = $this->createDefault(); return $this->find($id) ?: $this->fallbackWorkspace($id); }

    public function firstActive(): ?Workspace { global $wpdb; $row = $wpdb->get_row('SELECT * FROM ' . Schema::workspacesTable() . " WHERE status = 'active' ORDER BY id ASC LIMIT 1"); return $row ? Workspace::fromRow($row) : null; }
    public function find(int $id): ?Workspace { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::workspacesTable() . ' WHERE id = %d LIMIT 1', $id)); return $row ? Workspace::fromRow($row) : null; }

    public function forUser(int $userId): ?Workspace {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT w.* FROM ' . Schema::workspacesTable() . ' w INNER JOIN ' . Schema::membershipsTable() . " m ON m.workspace_id = w.id WHERE m.user_id = %d AND m.status = 'active' AND w.status = 'active' ORDER BY m.id ASC LIMIT 1", $userId));
        return $row ? Workspace::fromRow($row) : null;
    }

    public function createForUser(int $userId, string $name, string $planKey = 'free', array $settings = []): int {
        global $wpdb;
        $now = current_time('mysql');
        $slug = $this->uniqueSlug($name);
        $wpdb->insert(Schema::workspacesTable(), [
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'owner_user_id' => $userId,
            'plan_key' => sanitize_key($planKey) ?: 'free',
            'onboarding_status' => (string) ($settings['onboarding_status'] ?? 'pending'),
            'onboarding_step' => (string) ($settings['onboarding_step'] ?? 'workspace'),
            'timezone' => (string) ($settings['timezone'] ?? wp_timezone_string()),
            'website_url' => isset($settings['website_url']) ? esc_url_raw((string) $settings['website_url']) : null,
            'intended_use' => isset($settings['intended_use']) ? sanitize_key((string) $settings['intended_use']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s']);
        $workspaceId = (int) $wpdb->insert_id;
        if ($workspaceId > 0) { $this->addMember($workspaceId, $userId, 'workspace_owner'); }
        return $workspaceId;
    }

    public function createDefault(): int {
        global $wpdb;
        $existing = $this->firstActive(); if ($existing) { return $existing->id; }
        $owner = get_current_user_id();
        if ($owner <= 0) { $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); $owner = $admins ? (int) $admins[0] : 0; }
        return $this->createForUser($owner, get_bloginfo('name') ?: 'Default Workspace', 'free', ['onboarding_status' => 'complete', 'onboarding_step' => 'complete']);
    }

    public function addMember(int $workspaceId, int $userId, string $role): bool {
        global $wpdb;
        $now = current_time('mysql');
        $existing = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::membershipsTable() . ' WHERE workspace_id = %d AND user_id = %d', $workspaceId, $userId));
        if ($existing > 0) { return true; }
        return $wpdb->insert(Schema::membershipsTable(), ['workspace_id' => $workspaceId, 'user_id' => $userId, 'role' => sanitize_key($role), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now], ['%d','%d','%s','%s','%s','%s']) !== false;
    }

    public function updatePlan(int $workspaceId, string $planKey): bool { global $wpdb; $planKey = sanitize_key($planKey) ?: 'free'; return $wpdb->update(Schema::workspacesTable(), ['plan_key' => $planKey, 'updated_at' => current_time('mysql')], ['id' => $workspaceId], ['%s','%s'], ['%d']) !== false; }

    public function updateOnboarding(int $workspaceId, string $status, string $step = ''): bool { global $wpdb; return $wpdb->update(Schema::workspacesTable(), ['onboarding_status' => sanitize_key($status), 'onboarding_step' => sanitize_key($step), 'updated_at' => current_time('mysql')], ['id' => $workspaceId], ['%s','%s','%s'], ['%d']) !== false; }

    public function updateSettings(int $workspaceId, array $settings): bool {
        global $wpdb;
        $data = ['updated_at' => current_time('mysql')]; $formats = ['%s'];
        foreach (['name' => '%s', 'timezone' => '%s', 'intended_use' => '%s'] as $key => $format) { if (isset($settings[$key])) { $data[$key] = sanitize_text_field((string) $settings[$key]); $formats[] = $format; } }
        if (isset($settings['website_url'])) { $data['website_url'] = esc_url_raw((string) $settings['website_url']); $formats[] = '%s'; }
        return $wpdb->update(Schema::workspacesTable(), $data, ['id' => $workspaceId], $formats, ['%d']) !== false;
    }

    public function uniqueSlug(string $name): string { global $wpdb; $base = sanitize_title($name) ?: 'workspace'; $slug = $base; $suffix = 2; while ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::workspacesTable() . ' WHERE slug = %s', $slug)) > 0) { $slug = $base . '-' . $suffix; $suffix++; } return $slug; }

    private function fallbackWorkspace(int $id): Workspace { return Workspace::fromRow((object) ['id' => $id, 'name' => 'Default Workspace', 'slug' => 'default', 'status' => 'active', 'owner_user_id' => 0, 'plan_key' => 'free', 'onboarding_status' => 'complete', 'onboarding_step' => 'complete', 'timezone' => wp_timezone_string(), 'website_url' => '', 'intended_use' => '', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]); }
}
