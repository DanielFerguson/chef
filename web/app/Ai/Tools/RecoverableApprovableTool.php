<?php

namespace App\Ai\Tools;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RecoverableApprovableTool extends RecoverableTool implements Approvable
{
    public function __construct(private readonly Tool&Approvable $approvableTool)
    {
        parent::__construct($approvableTool);
    }

    public function requireApproval(?string $reason = null): static
    {
        $this->approvableTool->requireApproval($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvableTool->withoutApproval();

        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        return $this->approvableTool->shouldRequestApproval($request);
    }
}
