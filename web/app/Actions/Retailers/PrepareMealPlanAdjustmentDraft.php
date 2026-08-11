<?php

namespace App\Actions\Retailers;

use App\Actions\Planning\ProposeMeal;
use App\Ai\Contracts\MealPlanAdjustmentDrafter;
use App\Ai\Data\MealPlanAdjustmentDraftRequest;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Enums\MealProposalStatus;
use App\Models\BasketRun;
use App\Models\Constraint;
use App\Models\GroceryRequirement;
use App\Models\MealPlanAdjustmentDraft;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrepareMealPlanAdjustmentDraft
{
    public function __construct(
        private readonly MealPlanAdjustmentDrafter $drafter,
        private readonly ProposeMeal $proposeMeal,
    ) {}

    public function handle(BasketRun $basketRun, MealPlanAdjustmentKind $kind): MealPlanAdjustmentDraft
    {
        $basketRun->load([
            'mealPlan.plannedMeals.mealSlot',
            'mealPlan.proposals',
            'groceryPlan.requirements.sources',
        ]);
        $mealPlan = $basketRun->mealPlan;
        $revision = (int) $mealPlan->revision;
        $existing = $mealPlan->adjustmentDrafts()
            ->where('originating_plan_revision', $revision)
            ->where('kind', $kind)
            ->first();
        if ($existing !== null) {
            $this->markReviewRequired($basketRun, $existing, $kind);

            return $existing->load('items.mealProposal');
        }
        if ($mealPlan->adjustmentDrafts()
            ->where('kind', $kind)
            ->where('status', MealPlanAdjustmentDraftStatus::Applied)
            ->exists()) {
            throw ValidationException::withMessages([
                'adjustment' => 'Chef already proposed and applied one automatic recovery for this problem. Review the plan manually.',
            ]);
        }
        if ($mealPlan->proposals->contains('status', MealProposalStatus::Pending)) {
            throw ValidationException::withMessages([
                'adjustment' => 'Review the household’s existing pending meal changes before Chef drafts another plan adjustment.',
            ]);
        }

        $actor = User::query()->find($basketRun->requested_by_user_id);
        if ($actor === null || ! $actor->can('update', $mealPlan)) {
            throw ValidationException::withMessages([
                'adjustment' => 'A current household plan editor is required to prepare adjustment proposals.',
            ]);
        }
        $requirements = $basketRun->groceryPlan->requirements;
        $blocked = $kind === MealPlanAdjustmentKind::ProductUnavailable
            ? $requirements->where('status', GroceryRequirementStatus::NeedsProduct)->values()
            : collect();
        if ($kind === MealPlanAdjustmentKind::ProductUnavailable && $blocked->isEmpty()) {
            throw ValidationException::withMessages([
                'adjustment' => 'No unavailable grocery requirement is attached to this basket run.',
            ]);
        }

        $meals = array_values($mealPlan->plannedMeals
            ->sortBy('meal_slot_id')
            ->map(fn (PlannedMeal $meal): array => [
                'planned_meal_id' => $meal->id,
                'meal_slot_id' => $meal->meal_slot_id,
                'title' => $meal->title,
                'summary' => $meal->summary,
                'estimated_minutes' => $meal->estimated_minutes,
                'estimated_cost_cents' => $meal->estimated_cost === null
                    ? null
                    : (int) round($meal->estimated_cost * 100),
            ])
            ->values()
            ->all());
        if ($meals === []) {
            throw ValidationException::withMessages([
                'adjustment' => 'The approved plan has no meals that can be adjusted.',
            ]);
        }
        $blockedRequirements = array_values($blocked->map(fn (GroceryRequirement $requirement): array => [
            'requirement_id' => $requirement->id,
            'name' => $requirement->display_name,
            'form' => $requirement->normalized_form,
            'quantity' => $requirement->quantity,
            'unit' => $requirement->unit,
        ])->values()->all());
        $explicitConstraints = array_values(Constraint::query()
            ->where('team_id', $mealPlan->team_id)
            ->orderBy('id')
            ->get()
            ->map(fn (Constraint $constraint): array => [
                'constraint_id' => $constraint->id,
                'person_id' => $constraint->person_id,
                'kind' => $constraint->kind->value,
                'subject' => $constraint->subject,
                'details' => $constraint->details,
            ])->values()->all());
        $request = new MealPlanAdjustmentDraftRequest(
            teamId: $mealPlan->team_id,
            mealPlanId: $mealPlan->id,
            basketRunId: $basketRun->id,
            kind: $kind->value,
            planRevision: $revision,
            currentSubtotalCents: $basketRun->chef_subtotal_cents,
            budgetTargetCents: $basketRun->groceryPlan?->effective_basket_target_cents,
            blockedRequirements: $blockedRequirements,
            meals: $meals,
            explicitConstraints: $explicitConstraints,
        );
        $result = $this->drafter->draft($request);
        $validatedItems = $this->validateItems(
            $result->items,
            $mealPlan->plannedMeals,
            $requirements,
            $blocked,
            $kind,
        );
        $inputFingerprint = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

        $draft = DB::transaction(function () use (
            $basketRun,
            $mealPlan,
            $actor,
            $kind,
            $revision,
            $inputFingerprint,
            $validatedItems,
        ): MealPlanAdjustmentDraft {
            $lockedRun = BasketRun::query()->lockForUpdate()->findOrFail($basketRun->id);
            $lockedPlan = $mealPlan->newQuery()->lockForUpdate()->findOrFail($mealPlan->id);
            $existing = $lockedPlan->adjustmentDrafts()
                ->where('originating_plan_revision', $revision)
                ->where('kind', $kind)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($lockedPlan->proposals()
                ->where('status', MealProposalStatus::Pending)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'adjustment' => 'A pending household proposal appeared while Chef was preparing the adjustment.',
                ]);
            }

            $draft = $lockedPlan->adjustmentDrafts()->create([
                'team_id' => $lockedPlan->team_id,
                'basket_run_id' => $lockedRun->id,
                'originating_plan_revision' => $revision,
                'kind' => $kind,
                'status' => MealPlanAdjustmentDraftStatus::Pending,
                'input_fingerprint' => $inputFingerprint,
                'generated_at' => now(),
            ]);

            foreach ($validatedItems as $item) {
                $meal = PlannedMeal::query()->findOrFail($item['planned_meal_id']);
                $proposal = $this->proposeMeal->handle(
                    $lockedPlan,
                    $actor,
                    $item['title'],
                    $meal->mealSlot,
                    $item['summary'],
                    $item['estimated_minutes'],
                    $item['estimated_cost_cents'] === null
                        ? null
                        : $item['estimated_cost_cents'] / 100,
                );
                $draft->items()->create([
                    'team_id' => $lockedPlan->team_id,
                    'meal_slot_id' => $meal->meal_slot_id,
                    'planned_meal_id' => $meal->id,
                    'meal_proposal_id' => $proposal->id,
                    'replacement_title' => $item['title'],
                    'replacement_summary' => $item['summary'],
                    'estimated_minutes' => $item['estimated_minutes'],
                    'estimated_cost_cents' => $item['estimated_cost_cents'],
                    'covered_requirement_ids' => $item['covered_requirement_ids'],
                ]);
            }

            $this->markReviewRequired($lockedRun, $draft, $kind);

            return $draft;
        });

        return $draft->refresh()->load('items.mealProposal');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, PlannedMeal>  $meals
     * @param  Collection<int, GroceryRequirement>  $requirements
     * @param  Collection<int, GroceryRequirement>  $blocked
     * @return list<array{planned_meal_id: int, meal_slot_id: int, title: string, summary: string|null, estimated_minutes: int|null, estimated_cost_cents: int|null, covered_requirement_ids: list<int>}>
     */
    private function validateItems(
        array $items,
        Collection $meals,
        Collection $requirements,
        Collection $blocked,
        MealPlanAdjustmentKind $kind,
    ): array {
        if ($items === []) {
            throw ValidationException::withMessages(['adjustment' => 'The adjustment agent returned no replacement meals.']);
        }

        $mealsById = $meals->keyBy('id');
        $requirementIds = $requirements->pluck('id')
            ->map(fn ($requirementId): int => (int) $requirementId)
            ->values()
            ->all();
        $seenSlots = [];
        $covered = [];
        $validated = [];

        foreach ($items as $item) {
            $plannedMealId = (int) ($item['planned_meal_id'] ?? 0);
            $mealSlotId = (int) ($item['meal_slot_id'] ?? 0);
            $meal = $mealsById->get($plannedMealId);
            $title = Str::squish((string) ($item['title'] ?? ''));
            $coveredIds = array_values(array_unique(array_map(
                static fn ($requirementId): int => (int) $requirementId,
                is_array($item['covered_requirement_ids'] ?? null) ? $item['covered_requirement_ids'] : [],
            )));

            if (! $meal instanceof PlannedMeal
                || $meal->meal_slot_id !== $mealSlotId
                || in_array($mealSlotId, $seenSlots, true)
                || $title === ''
                || mb_strlen($title) > 255
                || array_diff($coveredIds, $requirementIds) !== []) {
                throw ValidationException::withMessages([
                    'adjustment' => 'The adjustment agent referenced an invalid meal, slot, requirement, or replacement title.',
                ]);
            }
            $seenSlots[] = $mealSlotId;
            $covered = [...$covered, ...$coveredIds];
            $validated[] = [
                'planned_meal_id' => $plannedMealId,
                'meal_slot_id' => $mealSlotId,
                'title' => $title,
                'summary' => is_string($item['summary'] ?? null)
                    ? Str::limit(Str::squish($item['summary']), 2_000, '')
                    : null,
                'estimated_minutes' => is_numeric($item['estimated_minutes'] ?? null)
                    ? max(1, (int) $item['estimated_minutes'])
                    : null,
                'estimated_cost_cents' => is_numeric($item['estimated_cost_cents'] ?? null)
                    ? max(1, (int) $item['estimated_cost_cents'])
                    : null,
                'covered_requirement_ids' => $coveredIds,
            ];
        }

        if ($kind === MealPlanAdjustmentKind::ProductUnavailable
            && array_diff(
                $blocked->pluck('id')->map(fn ($requirementId): int => (int) $requirementId)->all(),
                array_unique($covered),
            ) !== []) {
            throw ValidationException::withMessages([
                'adjustment' => 'Every unavailable product must be covered by the coherent replacement proposal set.',
            ]);
        }

        return $validated;
    }

    private function markReviewRequired(
        BasketRun $basketRun,
        MealPlanAdjustmentDraft $draft,
        MealPlanAdjustmentKind $kind,
    ): void {
        $blockedRequirementCount = $kind === MealPlanAdjustmentKind::ProductUnavailable
            ? $draft->items()
                ->get()
                ->flatMap(fn ($item): array => $item->covered_requirement_ids ?? [])
                ->unique()
                ->count()
            : null;

        $basketRun->update([
            'status' => BasketRunStatus::NeedsPlanReview,
            'attention_kind' => $kind->value,
            'attention_details' => [
                'adjustment_draft_id' => $draft->id,
                'budget_target_cents' => $basketRun->groceryPlan?->effective_basket_target_cents,
                'selected_subtotal_cents' => $basketRun->chef_subtotal_cents,
                'blocked_requirement_count' => $blockedRequirementCount,
            ],
            'failure_code' => null,
            'failure_message' => null,
        ]);
    }
}
