<?php

namespace App\Actions\Shopping;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Ai\Exceptions\ShoppingListDraftUnavailable;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\ShoppingListGenerationMethod;
use App\Enums\ShoppingListGenerationStatus;
use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateShoppingList
{
    private const CLAIM_EXPIRY_MINUTES = 5;

    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly RecordMealPlanMilestone $recordMilestone,
        private readonly ShoppingListDrafter $drafter,
        private readonly BuildShoppingListDraftRequest $buildRequest,
        private readonly BuildDeterministicShoppingListDraft $buildFallback,
        private readonly ValidateShoppingListDraft $validateDraft,
        private readonly ShoppingItemIdentity $identity,
        private readonly MealPlanSafetyContext $safetyContext,
    ) {}

    public function handle(MealPlan $mealPlan, User $user, bool $force = false): ShoppingList
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot generate a shopping list for this plan.');
        }

        $this->assertGenerationAllowed($mealPlan);
        $planRevision = $mealPlan->revision;
        $safetyHash = $this->safetyContext->fingerprint($mealPlan);

        if (! hash_equals((string) $mealPlan->confirmed_safety_context_hash, $safetyHash)) {
            throw ValidationException::withMessages([
                'safety' => 'Review and reconfirm the meal plan safety details before preparing its shopping list.',
            ]);
        }

        try {
            $plannedMeals = $this->loadPlannedMeals($mealPlan);
            $request = $this->buildRequest->handle($mealPlan, $plannedMeals);
        } catch (Throwable $exception) {
            $this->markPreflightFailed($mealPlan, $user, $exception);

            throw $exception;
        }

        $contextHash = $this->generationContextHash($planRevision, $safetyHash, $request);
        $claim = $this->claim($mealPlan, $user, $contextHash, $force);

        if ($claim instanceof ShoppingList) {
            return $claim;
        }

        [$shoppingList, $claimToken] = $claim;

        try {
            [$requirements, $method] = $this->draftRequirements($mealPlan, $request);
            $persisted = $this->persist(
                $mealPlan,
                $user,
                $shoppingList,
                $claimToken,
                $planRevision,
                $safetyHash,
                $contextHash,
                $requirements,
                $method,
            );

            if ($persisted === null) {
                throw ValidationException::withMessages([
                    'meal_plan' => 'The plan or its safety requirements changed while the shopping list was being prepared. Try again.',
                ]);
            }

            return $persisted;
        } catch (Throwable $exception) {
            $this->markFailed($shoppingList->id, $claimToken, $exception);

            throw $exception;
        }
    }

    private function assertGenerationAllowed(MealPlan $mealPlan): void
    {
        if ($mealPlan->planning_confirmed_at === null) {
            throw ValidationException::withMessages([
                'meal_plan' => 'Confirm the meal plan before generating its shopping list.',
            ]);
        }

        $resolvedMealIds = $mealPlan->shoppingList?->mealResolutions()->pluck('planned_meal_id') ?? collect();
        $unresolvedCookableMeals = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $resolvedMealIds))
            ->count();

        if ($unresolvedCookableMeals > 0) {
            throw ValidationException::withMessages([
                'recipes' => $unresolvedCookableMeals.' cookable '.($unresolvedCookableMeals === 1 ? 'meal still needs' : 'meals still need').' a prepared recipe.',
            ]);
        }
    }

    /** @return EloquentCollection<int, PlannedMeal> */
    private function loadPlannedMeals(MealPlan $mealPlan): EloquentCollection
    {
        return $mealPlan->plannedMeals()
            ->whereNotNull('recipe_version_id')
            ->where('status', 'planned')
            ->with([
                'recipeVersion.ingredients',
                'mealSlot.participants.constraints',
            ])
            ->get()
            ->sortBy([
                fn (PlannedMeal $meal) => $meal->mealSlot->date->toDateString(),
                fn (PlannedMeal $meal) => $meal->mealSlot->position,
            ])
            ->values();
    }

    private function generationContextHash(int $planRevision, string $safetyHash, ShoppingListDraftRequest $request): string
    {
        return hash('sha256', json_encode([
            'plan_revision' => $planRevision,
            'safety_context_hash' => $safetyHash,
            'requirements_hash' => $request->fingerprint(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return ShoppingList|array{0: ShoppingList, 1: string} */
    private function claim(MealPlan $mealPlan, User $user, string $contextHash, bool $force): ShoppingList|array
    {
        return DB::transaction(function () use ($mealPlan, $user, $contextHash, $force): ShoppingList|array {
            $lockedPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $shoppingList = ShoppingList::query()->firstOrCreate(
                ['meal_plan_id' => $lockedPlan->id],
                [
                    'team_id' => $lockedPlan->team_id,
                    'created_by_user_id' => $user->id,
                    'source_plan_revision' => $lockedPlan->revision,
                    'status' => ShoppingListStatus::Draft,
                    'generation_status' => ShoppingListGenerationStatus::Pending,
                ],
            );
            $shoppingList = ShoppingList::query()->lockForUpdate()->findOrFail($shoppingList->id);

            if (! $force
                && $shoppingList->revision > 0
                && $shoppingList->source_plan_revision === $lockedPlan->revision
                && $shoppingList->generation_status === ShoppingListGenerationStatus::Ready
                && hash_equals((string) $shoppingList->generation_context_hash, $contextHash)
                && $shoppingList->stale_at === null) {
                return $shoppingList;
            }

            $claimActive = $shoppingList->generation_status === ShoppingListGenerationStatus::Processing
                && $shoppingList->generation_started_at?->isAfter(now()->subMinutes(self::CLAIM_EXPIRY_MINUTES));

            if ($claimActive) {
                throw ValidationException::withMessages([
                    'shopping_list' => 'This shopping list is already being prepared.',
                ]);
            }

            $claimToken = (string) Str::uuid();
            $shoppingList->update([
                'generation_status' => ShoppingListGenerationStatus::Processing,
                'generation_token' => $claimToken,
                'generation_attempts' => $shoppingList->generation_attempts + 1,
                'generation_context_hash' => $contextHash,
                'generation_failure_code' => null,
                'generation_failure_message' => null,
                'generation_started_at' => now(),
                'generation_completed_at' => null,
            ]);

            return [$shoppingList->refresh(), $claimToken];
        });
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: ShoppingListGenerationMethod} */
    private function draftRequirements(MealPlan $mealPlan, ShoppingListDraftRequest $request): array
    {
        if ($request->requirements === []) {
            return [[], ShoppingListGenerationMethod::DeterministicFallback];
        }

        try {
            $draft = $this->drafter->draft($request);

            return [
                $this->validateDraft->handle($draft, $request, ShoppingListItemSourceKind::PlanGenerated),
                ShoppingListGenerationMethod::OneShot,
            ];
        } catch (ShoppingListDraftUnavailable|ValidationException $exception) {
            Log::warning('One-shot shopping-list consolidation failed; using deterministic fallback.', [
                'meal_plan_id' => $mealPlan->id,
                'team_id' => $mealPlan->team_id,
                'failure_type' => $exception::class,
            ]);
        }

        $fallback = $this->buildFallback->handle($request);

        return [
            $this->validateDraft->handle($fallback, $request, ShoppingListItemSourceKind::Recipe),
            ShoppingListGenerationMethod::DeterministicFallback,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $requirements
     */
    private function persist(
        MealPlan $mealPlan,
        User $user,
        ShoppingList $shoppingList,
        string $claimToken,
        int $planRevision,
        string $safetyHash,
        string $contextHash,
        array $requirements,
        ShoppingListGenerationMethod $method,
    ): ?ShoppingList {
        return DB::transaction(function () use ($mealPlan, $user, $shoppingList, $claimToken, $planRevision, $safetyHash, $contextHash, $requirements, $method): ?ShoppingList {
            $lockedPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $lockedList = ShoppingList::query()->lockForUpdate()->findOrFail($shoppingList->id);

            if ($lockedList->generation_token !== $claimToken) {
                throw ValidationException::withMessages([
                    'shopping_list' => 'This shopping-list generation attempt is no longer current.',
                ]);
            }

            $currentSafetyHash = $this->safetyContext->fingerprint($lockedPlan);
            $currentMeals = $this->loadPlannedMeals($lockedPlan);
            $currentRequest = $this->buildRequest->handle($lockedPlan, $currentMeals);
            $currentContextHash = $this->generationContextHash($lockedPlan->revision, $currentSafetyHash, $currentRequest);

            if ($lockedPlan->revision !== $planRevision
                || ! hash_equals($safetyHash, $currentSafetyHash)
                || ! hash_equals($contextHash, $currentContextHash)
                || ! hash_equals((string) $lockedPlan->confirmed_safety_context_hash, $currentSafetyHash)) {
                $lockedList->update([
                    'generation_status' => ShoppingListGenerationStatus::Pending,
                    'generation_token' => null,
                    'generation_context_hash' => $currentContextHash,
                    'generation_failure_code' => 'context_changed',
                    'generation_failure_message' => 'The plan changed while the shopping list was being prepared. Try again.',
                    'generation_completed_at' => null,
                ]);

                return null;
            }

            [$categoriesBySignature, $categoriesByIdentity] = $this->existingCategoryCorrections($lockedList);
            $generatedSourceKinds = [
                ShoppingListItemSourceKind::Recipe->value,
                ShoppingListItemSourceKind::PlanGenerated->value,
            ];
            $lockedList->items()->whereIn('source_kind', $generatedSourceKinds)->delete();
            ksort($requirements);
            $position = 1;

            foreach ($requirements as $requirement) {
                $sources = $requirement['sources'];
                $category = $categoriesBySignature[$requirement['_source_signature']] ?? null;
                $category ??= $categoriesByIdentity[$requirement['_canonical_key']] ?? null;
                $category ??= $requirement['category'] ?? ShoppingListItemCategory::classify($requirement['name'])->value;
                unset(
                    $requirement['sources'],
                    $requirement['category'],
                    $requirement['_source_signature'],
                    $requirement['_canonical_key'],
                );
                $item = $lockedList->items()->create([
                    ...$requirement,
                    'team_id' => $lockedPlan->team_id,
                    'created_by_user_id' => $user->id,
                    'category' => $category,
                    'included' => true,
                    'position' => $position++,
                ]);
                $item->sources()->createMany($sources);
            }

            foreach ($lockedList->items()->whereNotIn('source_kind', $generatedSourceKinds)->orderBy('position')->get() as $preservedItem) {
                $preservedItem->update(['position' => $position++]);
            }

            $this->removeObsoleteMealResolutions($lockedPlan, $lockedList);
            $lockedList->update([
                'source_plan_revision' => $lockedPlan->revision,
                'status' => ShoppingListStatus::Draft,
                'generation_status' => ShoppingListGenerationStatus::Ready,
                'generation_token' => null,
                'generation_context_hash' => $contextHash,
                'last_generation_method' => $method,
                'generation_failure_code' => null,
                'generation_failure_message' => null,
                'generation_completed_at' => now(),
                'completed_at' => null,
                'stale_at' => null,
                'stale_reason' => null,
                'stale_diff' => null,
            ]);
            $summary = $method === ShoppingListGenerationMethod::OneShot
                ? 'Generated shopping list from retained recipe requirements using one-shot consolidation'
                : 'Generated shopping list from retained recipe requirements using deterministic fallback';
            $this->recordRevision->handle($lockedList, $user, $summary);
            $this->recordMilestone->handle($lockedPlan, $user, MealPlanMilestoneKind::ShoppingListGenerated);

            return $lockedList->refresh();
        });
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function existingCategoryCorrections(ShoppingList $shoppingList): array
    {
        $bySignature = [];
        $byIdentity = [];
        $generatedSourceKinds = [
            ShoppingListItemSourceKind::Recipe->value,
            ShoppingListItemSourceKind::PlanGenerated->value,
        ];
        $items = $shoppingList->items()
            ->whereIn('source_kind', $generatedSourceKinds)
            ->with('sources')
            ->get();

        foreach ($items as $item) {
            $signature = $item->sources
                ->map(fn ($source): string => $source->planned_meal_id.':'.$source->recipe_ingredient_id)
                ->sort()
                ->implode('|');
            $category = (string) $item->getRawOriginal('category');
            $bySignature[$signature] = $category;
            $byIdentity[$this->identity->key($item->name)] = $category;
        }

        return [$bySignature, $byIdentity];
    }

    private function removeObsoleteMealResolutions(MealPlan $mealPlan, ShoppingList $shoppingList): void
    {
        $currentCustomMealIds = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->pluck('id');
        $shoppingList->mealResolutions()->whereNotIn('planned_meal_id', $currentCustomMealIds)->delete();
        $obsoleteMealItems = $shoppingList->items()
            ->where('source_kind', ShoppingListItemSourceKind::PlannedMeal)
            ->whereHas('sources', fn ($query) => $query->whereNotIn('planned_meal_id', $currentCustomMealIds))
            ->get();

        foreach ($obsoleteMealItems as $obsoleteMealItem) {
            $obsoleteMealItem->delete();
        }
    }

    private function markFailed(int $shoppingListId, string $claimToken, Throwable $exception): void
    {
        DB::transaction(function () use ($shoppingListId, $claimToken): void {
            $shoppingList = ShoppingList::query()->lockForUpdate()->find($shoppingListId);

            if ($shoppingList === null || $shoppingList->generation_token !== $claimToken) {
                return;
            }

            $shoppingList->update([
                'generation_status' => ShoppingListGenerationStatus::Failed,
                'generation_token' => null,
                'generation_failure_code' => 'generation_failed',
                'generation_failure_message' => 'Chef could not prepare this shopping list. Try again.',
                'generation_completed_at' => now(),
            ]);
        });
        Log::error('Shopping-list generation failed.', [
            'shopping_list_id' => $shoppingListId,
            'failure_type' => $exception::class,
        ]);
    }

    private function markPreflightFailed(MealPlan $mealPlan, User $user, Throwable $exception): void
    {
        $shoppingListId = DB::transaction(function () use ($mealPlan, $user): int {
            $lockedPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $shoppingList = ShoppingList::query()->firstOrCreate(
                ['meal_plan_id' => $lockedPlan->id],
                [
                    'team_id' => $lockedPlan->team_id,
                    'created_by_user_id' => $user->id,
                    'source_plan_revision' => $lockedPlan->revision,
                    'status' => ShoppingListStatus::Draft,
                ],
            );
            $shoppingList->update([
                'generation_status' => ShoppingListGenerationStatus::Failed,
                'generation_token' => null,
                'generation_attempts' => $shoppingList->generation_attempts + 1,
                'generation_failure_code' => 'request_build_failed',
                'generation_failure_message' => 'Chef could not prepare this shopping list. Try again.',
                'generation_started_at' => now(),
                'generation_completed_at' => now(),
            ]);

            return $shoppingList->id;
        });
        Log::error('Shopping-list request preparation failed.', [
            'shopping_list_id' => $shoppingListId,
            'failure_type' => $exception::class,
        ]);
    }
}
