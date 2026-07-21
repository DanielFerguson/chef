<?php

namespace App\Actions\Automation;

use App\Enums\AutomationRunItemStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\AutomationRun;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartCartPreparation
{
    public function handle(
        ShoppingList $shoppingList,
        ShoppingListRevision $revision,
        RetailerConnection $connection,
        User $user,
        string $idempotencyKey,
    ): AutomationRun {
        if (! (bool) config('automation.cart_mutation_enabled')) {
            throw ValidationException::withMessages(['automation' => 'Woolworths cart preparation is not enabled in this environment.']);
        }

        if (! $user->can('update', $shoppingList) || ! $user->can('useForAutomation', $connection)) {
            throw new AuthorizationException('You cannot prepare this Woolworths cart.');
        }

        $idempotencyHash = hash('sha256', implode(':', [
            'woolworths-cart-v1',
            $shoppingList->id,
            $revision->id,
            $connection->id,
            $user->id,
            $idempotencyKey,
        ]));
        $existing = AutomationRun::query()->where('idempotency_key', $idempotencyHash)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->validateReadiness($shoppingList, $revision, $connection);
        $snapshotItems = collect($this->includedSnapshotItems($revision));

        if ($snapshotItems->isEmpty()) {
            throw ValidationException::withMessages(['shopping_list' => 'Add at least one included item that is not already in the pantry.']);
        }

        $preferences = $shoppingList->team->productPreferences()
            ->where(fn ($query) => $query->whereNull('retailer_id')->orWhere('retailer_id', $connection->retailer_id))
            ->get()
            ->keyBy(fn ($preference) => Str::lower((string) $preference->normalized_item_name));
        $frozenItems = $snapshotItems->map(function (array $item) use ($preferences): array {
            $normalizedName = Str::of((string) ($item['name'] ?? ''))->squish()->lower()->toString();
            $acceptSubstitutes = true;
            $maximumPrice = null;

            if ($preferences->has($normalizedName)) {
                $preference = $preferences->get($normalizedName);
                $acceptSubstitutes = $preference->accept_substitutes;
                $maximumPrice = $preference->maximum_price;
            }

            return [
                'shopping_list_item_id' => $item['id'] ?? null,
                'name' => $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unit' => $item['unit'] ?? null,
                'note' => $item['note'] ?? null,
                'optional' => (bool) ($item['optional'] ?? false),
                'estimated_price' => $item['estimated_price'] ?? null,
                'product_match' => $item['product_match'] ?? null,
                'accept_substitutes' => $acceptSubstitutes,
                'maximum_price' => $maximumPrice,
                'source_planned_meal_ids' => $item['source_planned_meal_ids'] ?? [],
            ];
        })->all();
        $frozenSnapshot = [
            'shopping_list_id' => $shoppingList->id,
            'shopping_list_revision_id' => $revision->id,
            'revision' => $revision->revision,
            'source_plan_revision' => $revision->snapshot['source_plan_revision'] ?? null,
            'approved_by_user_id' => $user->id,
            'approved_at' => now()->toIso8601String(),
            'items' => $frozenItems,
        ];
        $encoded = json_encode($frozenSnapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        $run = DB::transaction(function () use ($shoppingList, $revision, $connection, $user, $idempotencyHash, $frozenSnapshot, $encoded, $frozenItems): AutomationRun {
            $lockedConnection = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($lockedConnection->status !== RetailerConnectionStatus::Connected) {
                throw ValidationException::withMessages(['connection' => 'Reconnect Woolworths before preparing the cart.']);
            }

            $activeStatuses = collect(AutomationRunStatus::cases())->reject->isTerminal()->map->value->all();
            if ($lockedConnection->runs()->whereIn('status', $activeStatuses)->exists()) {
                throw ValidationException::withMessages(['automation' => 'This Woolworths connection already has an active cart run.']);
            }

            $run = AutomationRun::query()->create([
                'team_id' => $shoppingList->team_id,
                'shopping_list_id' => $shoppingList->id,
                'shopping_list_revision_id' => $revision->id,
                'retailer_connection_id' => $connection->id,
                'started_by_user_id' => $user->id,
                'status' => AutomationRunStatus::CheckingConnection,
                'idempotency_key' => $idempotencyHash,
                'frozen_snapshot' => $frozenSnapshot,
                'frozen_snapshot_checksum' => hash('sha256', $encoded),
                'limits' => [
                    'max_actions' => (int) config('automation.max_actions', 40),
                    'max_runtime_seconds' => (int) config('automation.max_runtime_seconds', 180),
                    'max_item_attempts' => (int) config('automation.max_item_attempts', 4),
                ],
                'started_at' => now(),
                'expires_at' => now()->addMinutes((int) config('automation.run_ttl_minutes', 60)),
            ]);

            foreach ($frozenItems as $position => $item) {
                $run->items()->create([
                    'team_id' => $run->team_id,
                    'shopping_list_item_id' => is_numeric($item['shopping_list_item_id']) ? (int) $item['shopping_list_item_id'] : null,
                    'position' => $position + 1,
                    'status' => AutomationRunItemStatus::Pending,
                    'requirement_snapshot' => $item,
                ]);
            }

            return $run;
        });

        AdvanceAutomationRunJob::dispatch($run->id)->onQueue((string) config('automation.queue', 'automation'));

        return $run->refresh();
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
