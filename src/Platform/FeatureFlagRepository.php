<?php
namespace QRBuzz\Platform;

use QRBuzz\Database\Schema;

class FeatureFlagRepository {

    private PlatformAuditRepository $audit;

    public function __construct(?PlatformAuditRepository $audit = null) { $this->audit = $audit ?: new PlatformAuditRepository(); }

    public function all(): array {
        global $wpdb;
        return $wpdb->get_results('SELECT f.*, u.display_name AS updated_by_name FROM ' . Schema::featureFlagsTable() . ' f LEFT JOIN ' . $wpdb->users . ' u ON u.ID = f.updated_by ORDER BY f.scope ASC, f.flag_key ASC') ?: [];
    }

    public function isEnabled(string $flagKey, int $workspaceId = 0, int $userId = 0): bool {
        global $wpdb;
        $flagKey = sanitize_key($flagKey);
        if ($userId > 0) {
            $userFlag = $wpdb->get_var($wpdb->prepare("SELECT current_state FROM " . Schema::featureFlagsTable() . " WHERE flag_key = %s AND scope = 'user' AND scope_id = %d LIMIT 1", $flagKey, $userId));
            if ($userFlag !== null) { return (bool) $userFlag; }
        }
        if ($workspaceId > 0) {
            $workspaceFlag = $wpdb->get_var($wpdb->prepare("SELECT current_state FROM " . Schema::featureFlagsTable() . " WHERE flag_key = %s AND scope = 'workspace' AND scope_id = %d LIMIT 1", $flagKey, $workspaceId));
            if ($workspaceFlag !== null) { return (bool) $workspaceFlag; }
        }
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT current_state FROM " . Schema::featureFlagsTable() . " WHERE flag_key = %s AND scope = 'global' ORDER BY id ASC LIMIT 1", $flagKey));
    }

    public function set(int $id, bool $enabled, int $actorId): bool {
        global $wpdb;
        $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::featureFlagsTable() . ' WHERE id = %d LIMIT 1', $id));
        if (!$before) { return false; }
        $result = $wpdb->update(Schema::featureFlagsTable(), ['current_state' => $enabled ? 1 : 0, 'updated_by' => $actorId, 'updated_at' => current_time('mysql')], ['id' => $id], ['%d','%d','%s'], ['%d']);
        if ($result !== false) {
            $this->audit->record('feature_flag_changed', 'Feature flag ' . $before->flag_key . ' was ' . ($enabled ? 'enabled' : 'disabled') . '.', ['actor_id' => $actorId, 'entity_type' => 'feature_flag', 'entity_id' => $id, 'before' => ['enabled' => (bool) $before->current_state], 'after' => ['enabled' => $enabled]]);
        }
        return $result !== false;
    }
}
