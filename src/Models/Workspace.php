<?php
namespace QRBuzz\Models;

class Workspace {

    public int $id;
    public string $name;
    public string $slug;
    public string $status;
    public int $ownerUserId;
    public string $planKey;
    public string $createdAt;
    public string $updatedAt;

    public static function fromRow(object $row): self {
        $workspace = new self();
        $workspace->id = (int) $row->id;
        $workspace->name = (string) $row->name;
        $workspace->slug = (string) $row->slug;
        $workspace->status = (string) ($row->status ?? 'active');
        $workspace->ownerUserId = isset($row->owner_user_id) ? (int) $row->owner_user_id : 0;
        $workspace->planKey = (string) ($row->plan_key ?? 'free');
        $workspace->createdAt = (string) $row->created_at;
        $workspace->updatedAt = (string) $row->updated_at;

        return $workspace;
    }
}
