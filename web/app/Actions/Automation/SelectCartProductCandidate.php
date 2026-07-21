<?php

namespace App\Actions\Automation;

use App\Enums\CartProductPlanItemStatus;
use App\Enums\CartProductPlanStatus;
use App\Models\CartProductPlan;
use App\Models\CartProductPlanItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SelectCartProductCandidate
{
    public function handle(CartProductPlanItem $item, User $user, int $candidateIndex): CartProductPlan
    {
        if (! $user->can('update', $item)) {
            throw new AuthorizationException('You cannot change this Woolworths product plan.');
        }

        return DB::transaction(function () use ($item, $candidateIndex): CartProductPlan {
            $lockedItem = CartProductPlanItem::query()->lockForUpdate()->findOrFail($item->id);
            $plan = CartProductPlan::query()->lockForUpdate()->findOrFail($lockedItem->cart_product_plan_id);

            if ($plan->status !== CartProductPlanStatus::NeedsReview) {
                throw ValidationException::withMessages([
                    'product_plan' => 'Only an unfrozen product plan awaiting review can be changed.',
                ]);
            }

            $candidate = $lockedItem->candidates[$candidateIndex] ?? null;

            if (! is_array($candidate) || ! $this->isExactWoolworthsProduct($candidate)) {
                throw ValidationException::withMessages([
                    'candidate' => 'Choose one of the recorded Woolworths product candidates.',
                ]);
            }

            $lockedItem->update([
                'status' => CartProductPlanItemStatus::Exact,
                'selected_product' => [...$candidate, 'pack_count' => max(1, (int) ($candidate['pack_count'] ?? 1))],
                'decision_reason' => 'user_selected_candidate',
            ]);

            $items = $plan->items()->get();
            $exact = $items->where('status', CartProductPlanItemStatus::Exact)->count();
            $ambiguous = $items->where('status', CartProductPlanItemStatus::Ambiguous)->count();
            $unresolved = $items->where('status', CartProductPlanItemStatus::Unresolved)->count();
            $plan->update([
                'status' => $exact === $items->count()
                    ? CartProductPlanStatus::Ready
                    : CartProductPlanStatus::NeedsReview,
                'snapshot' => [
                    ...($plan->snapshot ?? []),
                    'exact_items' => $exact,
                    'ambiguous_items' => $ambiguous,
                    'unresolved_items' => $unresolved,
                    'last_decision_at' => now()->toIso8601String(),
                ],
            ]);

            return $plan->refresh()->load('items');
        });
    }

    /** @param array<string, mixed> $product */
    private function isExactWoolworthsProduct(array $product): bool
    {
        if (! filled($product['external_id'] ?? null)
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
}
