<?php
namespace QRBuzz\Database;

use QRBuzz\Models\Campaign;

class CampaignRepository {

    /** @return Campaign[] */
    public function all(bool $includeArchived = true): array {
        global $wpdb;

        $campaignsTable = Schema::campaignsTable();
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $where = $includeArchived ? '' : "WHERE c.status = 'active'";
        $rows = $wpdb->get_results("SELECT c.*, COUNT(DISTINCT q.id) AS qr_count, SUM(CASE WHEN q.is_trackable = 1 THEN 1 ELSE 0 END) AS dynamic_count, SUM(CASE WHEN q.is_trackable = 0 THEN 1 ELSE 0 END) AS static_count, COUNT(s.id) AS scan_count FROM {$campaignsTable} c LEFT JOIN {$qrcodesTable} q ON q.campaign_id = c.id LEFT JOIN {$scansTable} s ON s.qr_id = q.id {$where} GROUP BY c.id ORDER BY c.status ASC, c.name ASC") ?: [];

        return array_map([Campaign::class, 'fromRow'], $rows);
    }

    /** @return Campaign[] */
    public function active(): array {
        return $this->all(false);
    }

    public function find(int $id): ?Campaign {
        global $wpdb;

        $campaignsTable = Schema::campaignsTable();
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT c.*, COUNT(DISTINCT q.id) AS qr_count, SUM(CASE WHEN q.is_trackable = 1 THEN 1 ELSE 0 END) AS dynamic_count, SUM(CASE WHEN q.is_trackable = 0 THEN 1 ELSE 0 END) AS static_count, COUNT(s.id) AS scan_count FROM {$campaignsTable} c LEFT JOIN {$qrcodesTable} q ON q.campaign_id = c.id LEFT JOIN {$scansTable} s ON s.qr_id = q.id WHERE c.id = %d GROUP BY c.id LIMIT 1", $id));

        return $row ? Campaign::fromRow($row) : null;
    }

    public function create(string $name, string $description): int {
        global $wpdb;

        $now = current_time('mysql');
        $slug = $this->uniqueSlug($name);
        $wpdb->insert(Schema::campaignsTable(), ['name' => $name, 'slug' => $slug, 'description' => $description, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now], ['%s', '%s', '%s', '%s', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, string $name, string $description, string $status): bool {
        global $wpdb;

        $status = in_array($status, ['active', 'archived'], true) ? $status : 'active';
        $result = $wpdb->update(Schema::campaignsTable(), ['name' => $name, 'description' => $description, 'status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id], ['%s', '%s', '%s', '%s'], ['%d']);

        return $result !== false;
    }

    public function setStatus(int $id, string $status): bool {
        global $wpdb;

        $status = $status === 'archived' ? 'archived' : 'active';
        $result = $wpdb->update(Schema::campaignsTable(), ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id], ['%s', '%s'], ['%d']);

        return $result !== false;
    }

    public function canDelete(int $id): bool {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE campaign_id = %d', $id)) === 0;
    }

    public function delete(int $id): bool {
        global $wpdb;

        if (!$this->canDelete($id)) {
            return false;
        }

        return $wpdb->delete(Schema::campaignsTable(), ['id' => $id], ['%d']) !== false;
    }

    public function campaignOptions(): array {
        $options = [];
        foreach ($this->active() as $campaign) {
            $options[$campaign->id] = $campaign->name;
        }

        return $options;
    }

    private function uniqueSlug(string $name): string {
        global $wpdb;

        $base = sanitize_title($name) ?: 'campaign';
        $slug = $base;
        $suffix = 2;

        while ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::campaignsTable() . ' WHERE slug = %s', $slug)) > 0) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
