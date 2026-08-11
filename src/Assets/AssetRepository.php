<?php
namespace QRBuzz\Assets;

use QRBuzz\Database\Schema;
use QRBuzz\Models\Asset;
use QRBuzz\Workspace\WorkspaceService;

class AssetRepository {

    private WorkspaceService $workspaces;

    public function __construct(?WorkspaceService $workspaces = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
    }

    /** @return Asset[] */
    public function all(int $limit = 100): array {
        global $wpdb;
        $assets = Schema::assetsTable();
        $qrcodes = Schema::qrcodesTable();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, COUNT(q.id) AS usage_count
             FROM {$assets} a
             LEFT JOIN {$qrcodes} q ON q.logo_asset_id = a.id AND q.workspace_id = a.workspace_id
             WHERE a.workspace_id = %d AND a.user_id = %d
             GROUP BY a.id
             ORDER BY a.updated_at DESC
             LIMIT %d",
            $this->workspaceId(),
            $this->userId(),
            $limit
        )) ?: [];
        return array_map([Asset::class, 'fromRow'], $rows);
    }

    public function find(int $id): ?Asset {
        global $wpdb;
        $assets = Schema::assetsTable();
        $qrcodes = Schema::qrcodesTable();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, COUNT(q.id) AS usage_count
             FROM {$assets} a
             LEFT JOIN {$qrcodes} q ON q.logo_asset_id = a.id AND q.workspace_id = a.workspace_id
             WHERE a.id = %d AND a.workspace_id = %d AND a.user_id = %d
             GROUP BY a.id
             LIMIT 1",
            $id,
            $this->workspaceId(),
            $this->userId()
        ));
        return $row ? Asset::fromRow($row) : null;
    }

    public function create(array $data): int {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(Schema::assetsTable(), [
            'workspace_id' => $this->workspaceId(),
            'user_id' => get_current_user_id(),
            'display_name' => sanitize_text_field((string) $data['display_name']),
            'original_filename' => sanitize_file_name((string) $data['original_filename']),
            'stored_filename' => sanitize_file_name((string) $data['stored_filename']),
            'mime_type' => sanitize_mime_type((string) $data['mime_type']),
            'file_size' => absint($data['file_size']),
            'width' => absint($data['width']),
            'height' => absint($data['height']),
            'storage_path' => ltrim(str_replace('\\', '/', (string) $data['storage_path']), '/'),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%s','%s']);
        return (int) $wpdb->insert_id;
    }

    public function rename(int $id, string $name): bool {
        global $wpdb;
        $name = sanitize_text_field($name);
        if ($name === '' || !$this->find($id)) {
            return false;
        }
        return $wpdb->update(Schema::assetsTable(), ['display_name' => $name, 'updated_at' => current_time('mysql')], ['id' => $id, 'workspace_id' => $this->workspaceId(), 'user_id' => $this->userId()], ['%s','%s'], ['%d','%d','%d']) !== false;
    }

    public function delete(int $id): bool {
        global $wpdb;
        if (!$this->find($id)) {
            return false;
        }
        return $wpdb->delete(Schema::assetsTable(), ['id' => $id, 'workspace_id' => $this->workspaceId(), 'user_id' => $this->userId()], ['%d','%d','%d']) !== false;
    }

    public function usageCount(int $id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE workspace_id = %d AND logo_asset_id = %d', $this->workspaceId(), $id));
    }

    private function workspaceId(): int {
        return $this->workspaces->id();
    }

    private function userId(): int {
        return get_current_user_id();
    }
}
