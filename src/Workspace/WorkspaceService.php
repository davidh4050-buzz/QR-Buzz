<?php
namespace QRBuzz\Workspace;

use QRBuzz\Database\WorkspaceRepository;
use QRBuzz\Models\Workspace;

class WorkspaceService {

    private WorkspaceRepository $repository;
    private ?Workspace $current = null;

    public function __construct(?WorkspaceRepository $repository = null) {
        $this->repository = $repository ?: new WorkspaceRepository();
    }

    public function current(): Workspace {
        if (!$this->current) {
            $this->current = $this->repository->default();
        }

        return $this->current;
    }

    public function id(): int {
        return $this->current()->id;
    }

    public function changePlan(string $planKey): bool {
        $updated = $this->repository->updatePlan($this->id(), $planKey);
        if ($updated) {
            $this->current = null;
        }

        return $updated;
    }
}
