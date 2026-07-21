<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\RetailerProductDiscovery;
use App\Enums\CartProductPlanItemStatus;
use App\Enums\CartProductPlanStatus;
use App\Models\CartProductPlan;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuildCartProductPlan
{
    public function __construct(
        private readonly BuildCartPreparationPreflight $buildPreflight,
        private readonly RetailerProductDiscovery $discovery,
    ) {}

    public function handle(
        ShoppingList $shoppingList,
        ShoppingListRevision $revision,
        RetailerConnection $connection,
        User $user,
        bool $force = false,
    ): CartProductPlan {
        if (! $user->can('update', $shoppingList) || ! $user->can('useForAutomation', $connection)) {
            throw new AuthorizationException('You cannot build this Woolworths product plan.');
        }

        if ($revision->shopping_list_id !== $shoppingList->id
            || $revision->team_id !== $shoppingList->team_id
            || $revision->revision !== $shoppingList->revision
            || $connection->team_id !== $shoppingList->team_id
            || $connection->retailer->slug !== 'woolworths') {
            throw ValidationException::withMessages([
                'shopping_list' => 'Build the product plan from this family’s current Woolworths shopping-list revision.',
            ]);
        }

        $requirements = $this->requirements($revision);
        $preflight = $this->buildPreflight->handle($shoppingList, $revision);
        $input = [
            'shopping_list_revision_id' => $revision->id,
            'revision' => $revision->revision,
            'retailer_id' => $connection->retailer_id,
            'safety_fingerprint' => $preflight['safety_fingerprint'],
            'requirements' => $requirements,
        ];
        $checksum = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $existing = CartProductPlan::query()
            ->where('shopping_list_revision_id', $revision->id)
            ->where('retailer_id', $connection->retailer_id)
            ->where('input_checksum', $checksum)
            ->whereIn('status', [CartProductPlanStatus::Ready->value, CartProductPlanStatus::NeedsReview->value])
            ->latest('id')
            ->first();

        if (! $force && $existing !== null) {
            return $existing->load('items');
        }

        CartProductPlan::query()
            ->where('shopping_list_revision_id', $revision->id)
            ->where('retailer_id', $connection->retailer_id)
            ->whereIn('status', [CartProductPlanStatus::Ready->value, CartProductPlanStatus::NeedsReview->value])
            ->update(['status' => CartProductPlanStatus::Superseded->value]);

        $startedAt = microtime(true);
        $unmatched = collect($requirements)
            ->filter(fn (array $requirement): bool => ! $this->isExactProduct($requirement['product_match'] ?? null))
            ->map(fn (array $requirement): array => [
                'id' => $requirement['id'],
                'name' => $requirement['name'],
                'quantity' => $requirement['quantity'],
                'unit' => $requirement['unit'],
            ])
            ->values()
            ->all();
        $discoveryFailed = false;

        try {
            $discovered = $this->discovery->discover($connection->retailer, $unmatched);
        } catch (Throwable) {
            $discovered = [];
            $discoveryFailed = true;
        }

        $candidateIndex = 0;
        $plannedItems = [];
        foreach ($requirements as $position => $requirement) {
            $existingMatch = $requirement['product_match'] ?? null;

            if ($this->isExactProduct($existingMatch)) {
                $plannedItems[] = [
                    'team_id' => $shoppingList->team_id,
                    'shopping_list_item_id' => $requirement['id'],
                    'position' => $position + 1,
                    'status' => CartProductPlanItemStatus::Exact,
                    'requirement_snapshot' => $requirement,
                    'candidates' => [$existingMatch],
                    'selected_product' => $existingMatch,
                    'decision_reason' => 'approved_exact_match',
                ];

                continue;
            }

            $candidates = array_values(array_filter(
                $discovered[$candidateIndex] ?? [],
                fn (array $candidate): bool => $this->isExactProduct($candidate),
            ));
            $candidateIndex++;
            $selected = ! $preflight['requires_exact_matches'] ? $this->confidentCandidate($candidates) : null;
            $plannedItems[] = [
                'team_id' => $shoppingList->team_id,
                'shopping_list_item_id' => $requirement['id'],
                'position' => $position + 1,
                'status' => $selected !== null
                    ? CartProductPlanItemStatus::Exact
                    : ($candidates === [] ? CartProductPlanItemStatus::Unresolved : CartProductPlanItemStatus::Ambiguous),
                'requirement_snapshot' => $requirement,
                'candidates' => $candidates,
                'selected_product' => $selected,
                'decision_reason' => $selected !== null
                    ? 'confident_read_only_discovery'
                    : ($preflight['requires_exact_matches'] && $candidates !== []
                        ? 'explicit_safety_match_required'
                        : ($candidates === [] ? 'no_candidate_found' : 'ambiguous_candidates')),
            ];
        }

        $ready = $plannedItems !== [] && collect($plannedItems)->every(
            fn (array $item): bool => $item['status'] === CartProductPlanItemStatus::Exact,
        );
        $snapshot = [
            'version' => 'chef.product-plan.v1',
            'input_checksum' => $checksum,
            'shopping_list_revision_id' => $revision->id,
            'retailer' => 'woolworths',
            'safety_fingerprint' => $preflight['safety_fingerprint'],
            'requires_exact_matches' => $preflight['requires_exact_matches'],
            'exact_items' => collect($plannedItems)->where('status', CartProductPlanItemStatus::Exact)->count(),
            'ambiguous_items' => collect($plannedItems)->where('status', CartProductPlanItemStatus::Ambiguous)->count(),
            'unresolved_items' => collect($plannedItems)->where('status', CartProductPlanItemStatus::Unresolved)->count(),
            'discovery_failed' => $discoveryFailed,
            'discovery_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ];

        return DB::transaction(function () use ($shoppingList, $revision, $connection, $checksum, $preflight, $ready, $snapshot, $plannedItems): CartProductPlan {
            $plan = CartProductPlan::query()->create([
                'team_id' => $shoppingList->team_id,
                'shopping_list_id' => $shoppingList->id,
                'shopping_list_revision_id' => $revision->id,
                'retailer_id' => $connection->retailer_id,
                'status' => $ready ? CartProductPlanStatus::Ready : CartProductPlanStatus::NeedsReview,
                'input_checksum' => $checksum,
                'safety_fingerprint' => $preflight['safety_fingerprint'],
                'snapshot' => $snapshot,
            ]);

            $plan->items()->createMany($plannedItems);

            return $plan->load('items');
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function requirements(ShoppingListRevision $revision): array
    {
        return collect(is_array($revision->snapshot['items'] ?? null) ? $revision->snapshot['items'] : [])
            ->filter(fn ($item): bool => is_array($item)
                && (bool) ($item['included'] ?? false)
                && ! (bool) ($item['in_pantry'] ?? false)
                && is_string($item['name'] ?? null)
                && trim($item['name']) !== '')
            ->map(fn (array $item): array => [
                'id' => is_numeric($item['id'] ?? null) ? (int) $item['id'] : null,
                'name' => trim((string) $item['name']),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null,
                'unit' => is_string($item['unit'] ?? null) ? $item['unit'] : null,
                'note' => is_string($item['note'] ?? null) ? $item['note'] : null,
                'optional' => (bool) ($item['optional'] ?? false),
                'estimated_price' => is_numeric($item['estimated_price'] ?? null) ? (float) $item['estimated_price'] : null,
                'product_match' => is_array($item['product_match'] ?? null) ? $item['product_match'] : null,
                'source_planned_meal_ids' => is_array($item['source_planned_meal_ids'] ?? null) ? $item['source_planned_meal_ids'] : [],
            ])
            ->values()
            ->all();
    }

    private function isExactProduct(mixed $product): bool
    {
        if (! is_array($product)
            || ! filled($product['external_id'] ?? null)
            || ! filled($product['product_name'] ?? null)
            || ! is_string($product['product_url'] ?? null)) {
            return false;
        }

        $url = parse_url($product['product_url']);

        return is_array($url)
            && ($url['scheme'] ?? null) === 'https'
            && in_array(strtolower((string) ($url['host'] ?? '')), ['woolworths.com.au', 'www.woolworths.com.au'], true)
            && str_starts_with((string) ($url['path'] ?? ''), '/shop/productdetails/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function confidentCandidate(array $candidates): ?array
    {
        $available = collect($candidates)
            ->filter(fn (array $candidate): bool => (bool) ($candidate['in_stock'] ?? true))
            ->sortByDesc(fn (array $candidate): float => (float) ($candidate['confidence'] ?? 0))
            ->values();
        $first = $available->get(0);
        $second = $available->get(1);
        $threshold = (float) config('automation.product_match_confidence', 0.92);
        $margin = (float) config('automation.product_match_margin', 0.08);

        if (! is_array($first) || (float) ($first['confidence'] ?? 0) < $threshold) {
            return null;
        }

        if (is_array($second)
            && ((float) ($first['confidence'] ?? 0) - (float) ($second['confidence'] ?? 0)) < $margin) {
            return null;
        }

        return [...$first, 'pack_count' => max(1, (int) ($first['pack_count'] ?? 1))];
    }
}
