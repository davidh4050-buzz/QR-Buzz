<?php
namespace QRBuzz\Workspace;

use QRBuzz\Database\WorkspaceRepository;
use QRBuzz\Models\Workspace;

class WorkspaceService {

    private WorkspaceRepository $repository;
    private ?Workspace $current = null;

    public function __construct(?WorkspaceRepository $repository = null) { $this->repository = $repository ?: new WorkspaceRepository(); }

    public function current(): Workspace {
        if (!$this->current) {
            $userId = get_current_user_id();
            $this->current = $userId > 0 ? ($this->repository->forUser($userId) ?: $this->repository->default()) : $this->repository->default();
        }
        return $this->current;
    }

    public function id(): int { return $this->current()->id; }
    public function isMember(?int $userId = null): bool { $userId = $userId ?: get_current_user_id(); $workspace = $userId > 0 ? $this->repository->forUser($userId) : null; return $workspace !== null && $workspace->id === $this->id(); }
    public function isOwner(?int $userId = null): bool { $userId = $userId ?: get_current_user_id(); return $userId > 0 && $this->current()->ownerUserId === $userId; }

    public function changePlan(string $planKey): bool {
        $updated = $this->repository->updatePlan($this->id(), $planKey);
        if ($updated) { $this->current = null; }
        return $updated;
    }

    public function reset(): void { $this->current = null; }
}
