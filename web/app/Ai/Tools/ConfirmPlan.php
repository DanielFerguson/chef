<?php

namespace App\Ai\Tools;

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\BuildMealPlanApprovalBrief;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ConfirmPlan implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly ApproveMealPlan $approvePlan,
        private readonly AssessMealPlanReadiness $assessReadiness,
        private readonly BuildMealPlanApprovalBrief $buildApprovalBrief,
    ) {}

    public function description(): Stringable|string
    {
        return 'Request human approval for the visible whole-plan draft. Pass the exact current plan revision from InspectMealPlan. The plan is not approved until this tool resumes after approval.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ((int) $request['plan_revision'] !== $this->mealPlan->refresh()->revision) {
            throw ValidationException::withMessages([
                'plan_revision' => 'The plan changed after this approval was requested. Inspect the current plan and request approval again.',
            ]);
        }

        $mealPlan = $this->approvePlan->handle($this->mealPlan, $this->actor);

        return json_encode([
            'approved' => true,
            'plan_progress' => $this->assessReadiness->handle($this->mealPlan->refresh()),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan_revision' => $schema->integer()->required(),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $brief = $this->buildApprovalBrief->handle($this->mealPlan->refresh(), $this->actor);

        return Approval::required($brief['grocery_preparation']['effect'].' Checkout remains human-controlled.');
    }
}
