<?php
namespace QRBuzz\Membership;

use QRBuzz\Database\Schema;
use QRBuzz\Workspace\WorkspaceService;

class UsageService {

    private WorkspaceService $workspaces;

    public function __construct(?WorkspaceService $workspaces = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
    }

    public function usage(string $resource): int {
        global $wpdb;
        $workspaceId = $this->workspaces->id();
        $resource = sanitize_key($resource);

        if ($resource === 'qr_assets') {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE workspace_id = %d', $workspaceId));
        }

        if ($resource === 'dynamic_qr_assets') {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE workspace_id = %d AND is_trackable = 1', $workspaceId));
        }

        if ($resource === 'campaigns') {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::campaignsTable() . ' WHERE workspace_id = %d', $workspaceId));
        }

        if ($resource === 'team_members') {
            return 1;
        }

        return 0;
    }

    public function smartRulesForAsset(int $qrId): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::destinationRulesTable() . ' WHERE qr_id = %d', $qrId));
    }

    public function summary(array $resources): array {
        $summary = [];
        foreach ($resources as $resource) {
            $summary[$resource] = $this->usage($resource);
        }
        return $summary;
    }
}
