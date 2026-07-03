<?php
namespace QRBuzz\Models;

class Campaign {

    public int $id;
    public string $name;
    public string $slug;
    public string $description;
    public string $status;
    public string $createdAt;
    public string $updatedAt;
    public int $qrCount;
    public int $dynamicCount;
    public int $staticCount;
    public int $scanCount;

    public static function fromRow(object $row): self {
        $campaign = new self();
        $campaign->id = (int) $row->id;
        $campaign->name = (string) $row->name;
        $campaign->slug = (string) $row->slug;
        $campaign->description = (string) ($row->description ?? '');
        $campaign->status = (string) ($row->status ?? 'active');
        $campaign->createdAt = (string) $row->created_at;
        $campaign->updatedAt = (string) $row->updated_at;
        $campaign->qrCount = isset($row->qr_count) ? (int) $row->qr_count : 0;
        $campaign->dynamicCount = isset($row->dynamic_count) ? (int) $row->dynamic_count : 0;
        $campaign->staticCount = isset($row->static_count) ? (int) $row->static_count : 0;
        $campaign->scanCount = isset($row->scan_count) ? (int) $row->scan_count : 0;

        return $campaign;
    }
}
