<?php

namespace App\Actions\Retailer;

use App\Actions\Automation\BuildCartPreparationPreflight;
use App\Actions\Automation\BuildCartProductPlan;
use App\Enums\CartProductPlanStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\CartProductPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartRetailerOrderRun
{
    public function __construct(
        private readonly BuildCartPreparationPreflight $buildPreflight,
        private readonly BuildCartProductPlan $buildProductPlan,
    ) {}

    public function handle(
        ShoppingList $shoppingList,
        ShoppingListRevision $revision,
        RetailerConnection $connection,
        User $user,
        string $idempotencyKey,
        bool $safetyAcknowledged = false,
        bool $productPlanReviewed = false,
    ): RetailerOrderRun {
        if (! (bool) config('automation.cart_mutation_enabled')) {
            throw ValidationException::withMessages(['automation' => 'Woolworths cart preparation is not enabled in this environment.']);
        }

        if (! $user->can('update', $shoppingList) || ! $user->can('useForAutomation', $connection)) {
            throw new AuthorizationException('You cannot prepare this Woolworths cart.');
        }

        $activeStatuses = collect(RetailerOrderRunStatus::cases())->reject->isTerminal()->map->value->all();
        $existing = RetailerOrderRun::query()
            ->where('shopping_list_revision_id', $revision->id)
            ->where('retailer_connection_id', $connection->id)
            ->whereIn('status', $activeStatuses)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing->load('items');
        }

        $this->validateReadiness($shoppingList, $revision, $connection);
        $preflight = $this->buildPreflight->handle($shoppingList, $revision);

        if (! $safetyAcknowledged) {
            throw ValidationException::withMessages(['safety_acknowledged' => 'Review the household safety context before preparing the cart.']);
        }

        $productPlan = $this->buildProductPlan->handle($shoppingList, $revision, $connection, $user);

        if ($productPlan->status !== CartProductPlanStatus::Ready) {
            throw ValidationException::withMessages([
                'shopping_list' => 'Finish the Woolworths product plan before opening an authenticated cart run. Ambiguous or unresolved items need an exact product choice.',
            ]);
        }

        if (! $productPlanReviewed) {
            throw ValidationException::withMessages([
                'product_plan_reviewed' => 'Review the exact Woolworths product plan before preparing the cart.',
            ]);
        }

        $existingForPlan = RetailerOrderRun::query()
            ->where('cart_product_plan_id', $productPlan->id)
            ->whereIn('status', $activeStatuses)
            ->latest('id')
            ->first();

        if ($existingForPlan !== null) {
            return $existingForPlan->load('items');
        }

        $snapshotItems = collect($this->includedSnapshotItems($revision));

        if ($snapshotItems->isEmpty()) {
            throw ValidationException::withMessages(['shopping_list' => 'Add at least one included item that is not already in the pantry.']);
        }

        $preferences = $shoppingList->team->productPreferences()
            ->where(fn ($query) => $query->whereNull('retailer_id')->orWhere('retailer_id', $connection->retailer_id))
            ->get()
            ->keyBy(fn ($preference) => Str::lower((string) $preference->normalized_item_name));
        $plannedProducts = $productPlan->items->keyBy(
            fn ($item): string => $item->shopping_list_item_id !== null
                ? 'item:'.$item->shopping_list_item_id
                : 'position:'.$item->position,
        );
        $frozenItems = $snapshotItems->values()->map(function (array $item, int $position) use ($preferences, $preflight, $plannedProducts): array {
            $normalizedName = Str::of((string) ($item['name'] ?? ''))->squish()->lower()->toString();
            $acceptSubstitutes = true;
            $maximumPrice = null;

            if ($preferences->has($normalizedName)) {
                $preference = $preferences->get($normalizedName);
                $acceptSubstitutes = $preference->accept_substitutes;
                $maximumPrice = $preference->maximum_price;
            }

            $productPlanItem = $plannedProducts->get(is_numeric($item['id'] ?? null)
                ? 'item:'.(int) $item['id']
                : 'position:'.($position + 1));

            return [
                'shopping_list_item_id' => $item['id'] ?? null,
                'name' => $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unit' => $item['unit'] ?? null,
                'note' => $item['note'] ?? null,
                'optional' => (bool) ($item['optional'] ?? false),
                'estimated_price' => $item['estimated_price'] ?? null,
                'product_match' => $productPlanItem?->selected_product,
                'accept_substitutes' => ! $preflight['requires_exact_matches'] && $acceptSubstitutes,
                'maximum_price' => $maximumPrice,
                'source_planned_meal_ids' => $item['source_planned_meal_ids'] ?? [],
            ];
        })->all();

        $run = DB::transaction(function () use ($shoppingList, $revision, $connection, $user, $frozenItems, $productPlan, $activeStatuses): RetailerOrderRun {
            $lockedConnection = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $lockedProductPlan = CartProductPlan::query()->lockForUpdate()->findOrFail($productPlan->id);

            if ($lockedConnection->status !== RetailerConnectionStatus::Connected) {
                throw ValidationException::withMessages(['connection' => 'Reconnect Woolworths before preparing the cart.']);
            }

            if ($lockedProductPlan->status !== CartProductPlanStatus::Ready
                || $lockedProductPlan->shopping_list_revision_id !== $revision->id
                || $lockedProductPlan->retailer_id !== $connection->retailer_id) {
                throw ValidationException::withMessages([
                    'product_plan' => 'The reviewed Woolworths product plan changed before it could be frozen.',
                ]);
            }

            $existingLocked = RetailerOrderRun::query()
                ->where('shopping_list_revision_id', $revision->id)
                ->where('retailer_connection_id', $lockedConnection->id)
                ->whereIn('status', $activeStatuses)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingLocked !== null) {
                return $existingLocked->load('items');
            }

            if (RetailerOrderRun::query()
                ->where('retailer_connection_id', $lockedConnection->id)
                ->whereIn('status', $activeStatuses)
                ->exists()) {
                throw ValidationException::withMessages(['automation' => 'This Woolworths connection already has an active order run.']);
            }

            $run = RetailerOrderRun::query()->create([
                'team_id' => $shoppingList->team_id,
                'shopping_list_id' => $shoppingList->id,
                'shopping_list_revision_id' => $revision->id,
                'retailer_connection_id' => $connection->id,
                'started_by_user_id' => $user->id,
                'cart_product_plan_id' => $lockedProductPlan->id,
                'status' => RetailerOrderRunStatus::PreparingCart,
                'limits' => [
                    'max_actions' => (int) config('automation.max_actions', 40),
                    'max_runtime_seconds' => (int) config('automation.max_runtime_seconds', 180),
                    'max_item_attempts' => (int) config('automation.max_item_attempts', 4),
                ],
                'expires_at' => now()->addMinutes((int) config('automation.run_ttl_minutes', 60)),
            ]);

            foreach ($frozenItems as $position => $item) {
                $run->items()->create([
                    'team_id' => $run->team_id,
                    'shopping_list_item_id' => is_numeric($item['shopping_list_item_id']) ? (int) $item['shopping_list_item_id'] : null,
                    'position' => $position + 1,
                    'status' => RetailerOrderRunItemStatus::Pending,
                    'requirement_snapshot' => $item,
                ]);
            }

            $lockedProductPlan->update([
                'status' => CartProductPlanStatus::Frozen,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'frozen_at' => now(),
                'snapshot' => [
                    ...($lockedProductPlan->snapshot ?? []),
                    'frozen_snapshot_checksum' => hash('sha256', json_encode(
                        $frozenItems,
                        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                    )),
                ],
            ]);

            return $run;
        });

        AdvanceRetailerOrderRunJob::dispatch($run->id)->onQueue((string) config('automation.queue', 'automation'));

        return $run->refresh()->load('items');
    }

    private function validateReadiness(
        ShoppingList $shoppingList,
        ShoppingListRevision $revision,
        RetailerConnection $connection,
    ): void {
        if ($revision->shopping_list_id !== $shoppingList->id
            || $revision->team_id !== $shoppingList->team_id
            || $revision->revision !== $shoppingList->revision) {
            throw ValidationException::withMessages(['shopping_list_revision' => 'Choose the current shopping-list revision before preparing the cart.']);
        }

        if ($shoppingList->stale_at !== null
            || $shoppingList->source_plan_revision !== $shoppingList->mealPlan->revision) {
            throw ValidationException::withMessages(['shopping_list' => 'Refresh this shopping list from the current meal plan before preparing the cart.']);
        }

        if ($connection->team_id !== $shoppingList->team_id || $connection->retailer->slug !== 'woolworths') {
            throw ValidationException::withMessages(['connection' => 'Use this family’s Woolworths connection.']);
        }

        $resolvedMealIds = $shoppingList->mealResolutions()->pluck('planned_meal_id');
        $unresolvedRecipes = $shoppingList->mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $resolvedMealIds))
            ->exists();

        if ($unresolvedRecipes) {
            throw ValidationException::withMessages(['shopping_list' => 'Resolve every planned meal’s ingredients before preparing the cart.']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function includedSnapshotItems(ShoppingListRevision $revision): array
    {
        $items = $revision->snapshot['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $included = [];

        foreach ($items as $item) {
            if (! is_array($item)
                || ! (bool) ($item['included'] ?? false)
                || (bool) ($item['in_pantry'] ?? false)) {
                continue;
            }

            $included[] = $item;
        }

        return $included;
    }
}
