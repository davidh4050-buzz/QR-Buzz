<?php
namespace QRBuzz\Database;

use QRBuzz\Models\Workspace;

class WorkspaceRepository {

    public function default(): Workspace {
        $workspace = $this->firstActive();
        if ($workspace) {
            return $workspace;
        }

        $id = $this->createDefault();
        return $this->find($id) ?: $this->fallbackWorkspace($id);
    }

    public function firstActive(): ?Workspace {
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . Schema::workspacesTable() . " WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        return $row ? Workspace::fromRow($row) : null;
    }

    public function find(int $id): ?Workspace {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::workspacesTable() . ' WHERE id = %d LIMIT 1', $id));
        return $row ? Workspace::fromRow($row) : null;
    }

    public function createDefault(): int {
        global $wpdb;
        $existing = $this->firstActive();
        if ($existing) {
            return $existing->id;
        }

        $now = current_time('mysql');
        $owner = get_current_user_id();
        if ($owner <= 0) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $owner = $admins ? (int) $admins[0] : 0;
        }

        $wpdb->insert(Schema::workspacesTable(), [
            'name' => get_bloginfo('name') ?: 'Default Workspace',
            'slug' => 'default',
            'status' => 'active',
            'owner_user_id' => $owner,
            'plan_key' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    public function updatePlan(int $workspaceId, string $planKey): bool {
        global $wpdb;
        $planKey = sanitize_key($planKey) ?: 'free';
        $result = $wpdb->update(Schema::workspacesTable(), ['plan_key' => $planKey, 'updated_at' => current_time('mysql')], ['id' => $workspaceId], ['%s', '%s'], ['%d']);
        return $result !== false;
    }

    private function fallbackWorkspace(int $id): Workspace {
        return Workspace::fromRow((object) [
            'id' => $id,
            'name' => 'Default Workspace',
            'slug' => 'default',
            'status' => 'active',
            'owner_user_id' => 0,
            'plan_key' => 'free',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }
}
