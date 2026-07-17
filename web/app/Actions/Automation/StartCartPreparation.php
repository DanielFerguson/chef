<?php

namespace App\Actions\Automation;

use App\Automation\RetailerOriginPolicy;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserConnectionStatus;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use App\Models\Budget;
use App\Models\Retailer;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartCartPreparation
{
    public function __construct(private readonly RetailerOriginPolicy $origins) {}

    public function handle(
        ShoppingList $shoppingList,
        User $user,
        Retailer $retailer,
        BrowserConnection $connection,
        int $expectedRevision,
    ): AutomationRun {
        if (! $user->can('update', $shoppingList)) {
            throw new AuthorizationException('You cannot prepare a cart for this shopping list.');
        }

        $this->origins->originFor($retailer);

        return DB::transaction(function () use ($shoppingList, $user, $retailer, $connection, $expectedRevision): AutomationRun {
            $shoppingList = ShoppingList::query()->whereKey($shoppingList->id)->lockForUpdate()->firstOrFail();
            $connection = BrowserConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            if ($shoppingList->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['shopping_list' => 'The shopping list changed. Review it before preparing the cart.']);
            }

            if ($shoppingList->stale_at !== null || $shoppingList->revision < 1) {
                throw ValidationException::withMessages(['shopping_list' => 'Review and regenerate the shopping list before preparing a cart.']);
            }

            if ($connection->team_id !== $shoppingList->team_id
                || $connection->status !== BrowserConnectionStatus::Active
                || $connection->expires_at->isPast()) {
                throw ValidationException::withMessages(['browser_connection' => 'Pair an active Chef browser extension first.']);
            }

            $items = $shoppingList->items()
                ->where('included', true)
                ->where('in_pantry', false)
                ->with(['productMatch.retailProduct', 'ingredient'])
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['shopping_list' => 'Include at least one item before preparing a cart.']);
            }

            $existing = AutomationRun::query()
                ->where('shopping_list_id', $shoppingList->id)
                ->where('shopping_list_revision', $shoppingList->revision)
                ->where('retailer_id', $retailer->id)
                ->where('browser_connection_id', $connection->id)
                ->whereNotIn('status', [AutomationRunStatus::Completed, AutomationRunStatus::Failed, AutomationRunStatus::Cancelled, AutomationRunStatus::Expired])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $planBudget = Budget::query()->where('meal_plan_id', $shoppingList->meal_plan_id)->first();
            $householdBudget = Budget::query()->where('team_id', $shoppingList->team_id)->whereNull('meal_plan_id')->latest()->first();
            $preferences = $shoppingList->team->productPreferences()
                ->where(function ($query) use ($retailer): void {
                    $query->whereNull('retailer_id')->orWhere('retailer_id', $retailer->id);
                })
                ->get()
                ->keyBy('normalized_item_name');

            $scope = [
                'shopping_list_id' => $shoppingList->id,
                'shopping_list_revision' => $shoppingList->revision,
                'retailer' => ['id' => $retailer->id, 'name' => $retailer->name, 'slug' => $retailer->slug],
                'approved_budget' => $planBudget !== null ? $planBudget->amount : $householdBudget?->amount,
                'currency' => 'AUD',
                'items' => $items->map(function ($item) use ($preferences): array {
                    $match = $item->productMatch;
                    $preference = $preferences->get($item->normalized_name);

                    return [
                        'shopping_list_item_id' => $item->id,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'note' => $item->note,
                        'matched_product' => $match === null ? null : [
                            'name' => $match->retailProduct->name,
                            'external_id' => $match->retailProduct->external_id,
                            'pack_count' => $match->pack_count,
                            'estimated_total' => $match->estimated_total,
                        ],
                        'preference' => $preference === null ? null : [
                            'preferred_brand' => $preference->preferred_brand,
                            'preferred_pack' => $preference->preferred_pack,
                            'accept_substitutes' => $preference->accept_substitutes,
                            'maximum_price' => $preference->maximum_price,
                            'note' => $preference->note,
                        ],
                    ];
                })->values()->all(),
            ];

            return AutomationRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'team_id' => $shoppingList->team_id,
                'shopping_list_id' => $shoppingList->id,
                'shopping_list_revision' => $shoppingList->revision,
                'retailer_id' => $retailer->id,
                'browser_connection_id' => $connection->id,
                'requested_by_user_id' => $user->id,
                'status' => AutomationRunStatus::AwaitingBrowser,
                'scope_snapshot' => $scope,
                'progress' => ['total' => $items->count(), 'added' => 0, 'unresolved' => 0],
                'expires_at' => now()->addMinutes((int) config('ai.computer_use.run_expiry_minutes', 60)),
            ]);
        });
    }
}
