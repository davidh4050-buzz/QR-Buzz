<?php
namespace QRBuzz\Models;

use QRBuzz\Utils\DateTimeHelper;

class QRCode {

    public int $id;
    public int $workspaceId;
    public string $name;
    public string $destinationUrl;
    public string $shortcode;
    public string $createdAt;
    public string $updatedAt;
    public bool $active;
    public string $status;
    public ?string $fallbackUrl;
    public ?string $expiresAt;
    public ?string $scheduledUrl;
    public ?string $scheduledStartAt;
    public ?string $scheduledEndAt;
    public string $type;
    public array $payloadData;
    public ?string $staticPayload;
    public bool $isTrackable;
    public ?int $campaignId;
    public ?string $campaignName;
    public string $theme;
    public string $foregroundColor;
    public string $backgroundColor;
    public bool $transparentBackground;
    public string $errorCorrection;
    public int $margin;
    public int $logoAttachmentId;
    public int $logoSize;
    public string $dotStyle;
    public string $finderStyle;
    public string $finderDotStyle;
    public ?string $finderColor;
    public string $caption;
    public int $captionFontSize;
    public string $captionFontColor;
    public int $scanCount;
    public ?string $lastScan;

    public static function fromRow(object $row): self {
        $qrCode = new self();
        $qrCode->id = (int) $row->id;
        $qrCode->workspaceId = isset($row->workspace_id) ? (int) $row->workspace_id : 0;
        $qrCode->name = (string) $row->name;
        $qrCode->destinationUrl = (string) $row->destination_url;
        $qrCode->shortcode = (string) $row->shortcode;
        $qrCode->createdAt = (string) $row->created_at;
        $qrCode->updatedAt = (string) $row->updated_at;
        $qrCode->active = (bool) $row->active;
        $qrCode->status = isset($row->status) && (string) $row->status !== '' ? (string) $row->status : ($qrCode->active ? 'active' : 'paused');
        $qrCode->fallbackUrl = self::nullableString($row->fallback_url ?? null);
        $qrCode->expiresAt = self::nullableString($row->expires_at ?? null);
        $qrCode->scheduledUrl = self::nullableString($row->scheduled_url ?? null);
        $qrCode->scheduledStartAt = self::nullableString($row->scheduled_start_at ?? null);
        $qrCode->scheduledEndAt = self::nullableString($row->scheduled_end_at ?? null);
        $qrCode->type = isset($row->type) && (string) $row->type !== '' ? (string) $row->type : 'dynamic_url';
        $qrCode->payloadData = self::decodePayloadData($row->payload_data ?? null);
        $qrCode->staticPayload = self::nullableString($row->static_payload ?? null);
        $qrCode->isTrackable = isset($row->is_trackable) ? (bool) $row->is_trackable : $qrCode->type === 'dynamic_url';
        $qrCode->campaignId = isset($row->campaign_id) && $row->campaign_id !== null ? (int) $row->campaign_id : null;
        $qrCode->campaignName = self::nullableString($row->campaign_name ?? null);
        $qrCode->theme = isset($row->theme) && (string) $row->theme !== '' ? (string) $row->theme : 'classic';
        $qrCode->foregroundColor = isset($row->foreground_color) && (string) $row->foreground_color !== '' ? (string) $row->foreground_color : '#000000';
        $qrCode->backgroundColor = isset($row->background_color) && (string) $row->background_color !== '' ? (string) $row->background_color : '#ffffff';
        $qrCode->transparentBackground = !empty($row->transparent_background);
        $qrCode->errorCorrection = isset($row->error_correction) && (string) $row->error_correction !== '' ? (string) $row->error_correction : 'H';
        $qrCode->margin = isset($row->margin) ? (int) $row->margin : 12;
        $qrCode->logoAttachmentId = isset($row->logo_attachment_id) ? (int) $row->logo_attachment_id : 0;
        $qrCode->logoSize = isset($row->logo_size) ? (int) $row->logo_size : 20;
        $qrCode->dotStyle = isset($row->dot_style) && (string) $row->dot_style !== '' ? (string) $row->dot_style : 'square';
        $qrCode->finderStyle = isset($row->finder_style) && (string) $row->finder_style !== '' ? (string) $row->finder_style : 'square';
        $qrCode->finderDotStyle = isset($row->finder_dot_style) && (string) $row->finder_dot_style !== '' ? (string) $row->finder_dot_style : 'square';
        $qrCode->finderColor = self::nullableString($row->finder_color ?? null);
        $qrCode->caption = isset($row->caption) ? (string) $row->caption : '';
        $qrCode->captionFontSize = isset($row->caption_font_size) ? (int) $row->caption_font_size : 16;
        $qrCode->captionFontColor = isset($row->caption_font_color) && (string) $row->caption_font_color !== '' ? (string) $row->caption_font_color : '#000000';
        $qrCode->scanCount = isset($row->scan_count) ? (int) $row->scan_count : 0;
        $qrCode->lastScan = isset($row->last_scan) && $row->last_scan !== null ? (string) $row->last_scan : null;
        return $qrCode;
    }

    public function effectiveStatus(?int $timestamp = null): string { return $this->isExpired($timestamp) ? 'expired' : $this->status; }
    public function isExpired(?int $timestamp = null): bool { $expiresTimestamp = DateTimeHelper::utcTimestamp($this->expiresAt); return $expiresTimestamp ? $expiresTimestamp <= ($timestamp ?: current_time('timestamp', true)) : false; }
    public function isTrackable(): bool { return $this->isTrackable && $this->type === 'dynamic_url'; }

    private static function nullableString($value): ?string { if ($value === null) { return null; } $value = trim((string) $value); return $value === '' ? null : $value; }
    private static function decodePayloadData($value): array { if (!$value) { return []; } $decoded = json_decode((string) $value, true); return is_array($decoded) ? $decoded : []; }
}
