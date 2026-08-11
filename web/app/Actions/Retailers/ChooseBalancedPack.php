<?php

namespace App\Actions\Retailers;

use App\Models\GroceryRequirement;
use App\Models\RetailerProductCandidate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ChooseBalancedPack
{
    /**
     * @param  Collection<int, RetailerProductCandidate>  $candidates
     * @return array{
     *     candidate: RetailerProductCandidate,
     *     pack_count: int,
     *     total_quantity: float,
     *     waste_quantity: float|null,
     *     total_price_cents: int,
     *     reasoning: string
     * }
     */
    public function handle(GroceryRequirement $requirement, Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'candidates' => 'No validated product can satisfy this grocery requirement.',
            ]);
        }

        if ($requirement->quantity_unknown || $requirement->quantity === null) {
            $candidate = $candidates
                ->sortBy([
                    ['pack_quantity', 'asc'],
                    ['price_cents', 'asc'],
                    ['sku', 'asc'],
                ])
                ->firstOrFail();

            return [
                'candidate' => $candidate,
                'pack_count' => 1,
                'total_quantity' => (float) $candidate->pack_quantity,
                'waste_quantity' => null,
                'total_price_cents' => (int) $candidate->price_cents,
                'reasoning' => 'The recipe quantity cannot be compared safely with retail pack units, so Chef chose the smallest otherwise-valid pack.',
            ];
        }

        $options = $candidates->map(function (RetailerProductCandidate $candidate) use ($requirement): array {
            $packCount = max(1, (int) ceil($requirement->quantity / $candidate->pack_quantity));
            $totalQuantity = $packCount * $candidate->pack_quantity;

            return [
                'candidate' => $candidate,
                'pack_count' => $packCount,
                'total_quantity' => $totalQuantity,
                'waste_quantity' => $totalQuantity - $requirement->quantity,
                'total_price_cents' => $packCount * $candidate->price_cents,
            ];
        });
        $cheapest = $options->sortBy([
            ['total_price_cents', 'asc'],
            ['waste_quantity', 'asc'],
            ['candidate.sku', 'asc'],
        ])->firstOrFail();
        $leastWaste = $options->sortBy([
            ['waste_quantity', 'asc'],
            ['total_price_cents', 'asc'],
            ['candidate.sku', 'asc'],
        ])->firstOrFail();
        $chooseLeastWaste = $leastWaste['total_price_cents'] * 100 <= $cheapest['total_price_cents'] * 110;
        $chosen = $chooseLeastWaste ? $leastWaste : $cheapest;

        return [
            ...$chosen,
            'reasoning' => $chooseLeastWaste
                ? 'Chef chose the least-waste valid pack combination because it costs no more than 10% above the cheapest option.'
                : 'The least-waste option costs more than 10% above the cheapest valid combination, so Chef chose the lowest total price.',
        ];
    }
}
