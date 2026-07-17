<?php
namespace QRBuzz\Models;

class Workspace {

    public int $id;
    public string $name;
    public string $slug;
    public string $status;
    public int $ownerUserId;
    public string $planKey;
    public string $onboardingStatus;
    public string $onboardingStep;
    public string $timezone;
    public string $websiteUrl;
    public string $intendedUse;
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
        $workspace->onboardingStatus = (string) ($row->onboarding_status ?? 'complete');
        $workspace->onboardingStep = (string) ($row->onboarding_step ?? '');
        $workspace->timezone = (string) ($row->timezone ?? '');
        $workspace->websiteUrl = (string) ($row->website_url ?? '');
        $workspace->intendedUse = (string) ($row->intended_use ?? '');
        $workspace->createdAt = (string) $row->created_at;
        $workspace->updatedAt = (string) $row->updated_at;
        return $workspace;
    }

    public function onboardingComplete(): bool { return $this->onboardingStatus === 'complete'; }
}
