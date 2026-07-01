<?php
namespace QRBuzz\Models;

class QRCode {

    public int $id;
    public string $name;
    public string $destinationUrl;
    public string $shortcode;
    public string $createdAt;
    public string $updatedAt;
    public bool $active;
    public int $scanCount;
    public ?string $lastScan;

    public static function fromRow(object $row): self {
        $qrCode = new self();
        $qrCode->id = (int) $row->id;
        $qrCode->name = (string) $row->name;
        $qrCode->destinationUrl = (string) $row->destination_url;
        $qrCode->shortcode = (string) $row->shortcode;
        $qrCode->createdAt = (string) $row->created_at;
        $qrCode->updatedAt = (string) $row->updated_at;
        $qrCode->active = (bool) $row->active;
        $qrCode->scanCount = isset($row->scan_count) ? (int) $row->scan_count : 0;
        $qrCode->lastScan = isset($row->last_scan) && $row->last_scan !== null ? (string) $row->last_scan : null;

        return $qrCode;
    }
}
