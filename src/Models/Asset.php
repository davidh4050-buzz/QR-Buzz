<?php
namespace QRBuzz\Models;

class Asset {

    public int $id;
    public int $workspaceId;
    public int $userId;
    public string $displayName;
    public string $originalFilename;
    public string $storedFilename;
    public string $mimeType;
    public int $fileSize;
    public int $width;
    public int $height;
    public string $storagePath;
    public string $createdAt;
    public string $updatedAt;
    public string $url = '';
    public int $usageCount = 0;

    public static function fromRow(object $row): self {
        $asset = new self();
        $asset->id = (int) $row->id;
        $asset->workspaceId = isset($row->workspace_id) ? (int) $row->workspace_id : 0;
        $asset->userId = (int) $row->user_id;
        $asset->displayName = (string) $row->display_name;
        $asset->originalFilename = (string) $row->original_filename;
        $asset->storedFilename = (string) $row->stored_filename;
        $asset->mimeType = (string) $row->mime_type;
        $asset->fileSize = (int) $row->file_size;
        $asset->width = (int) $row->width;
        $asset->height = (int) $row->height;
        $asset->storagePath = (string) $row->storage_path;
        $asset->createdAt = (string) $row->created_at;
        $asset->updatedAt = (string) $row->updated_at;
        $asset->usageCount = isset($row->usage_count) ? (int) $row->usage_count : 0;
        return $asset;
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'file_size' => $this->fileSize,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url,
            'usage_count' => $this->usageCount,
            'created_at' => $this->createdAt,
        ];
    }
}
