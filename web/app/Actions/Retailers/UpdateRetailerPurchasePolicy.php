<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Models\RetailerPurchasePolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateRetailerPurchasePolicy
{
    /** @param list<string> $preferredBrands */
    public function handle(
        Team $team,
        User $user,
        RetailerProvider $provider,
        RetailerHomeBrandPreference $homeBrandPreference,
        RetailerBulkPreference $bulkPreference,
        RetailerOrganicPreference $organicPreference,
        array $preferredBrands,
        ?int $defaultBasketTargetCents,
    ): RetailerPurchasePolicy {
        if (! $user->can('update', $team)) {
            throw new AuthorizationException('Only a household owner or admin can update grocery policy.');
        }

        $brands = collect($preferredBrands)
            ->map(fn (string $brand): string => Str::squish($brand))
            ->filter()
            ->unique(fn (string $brand): string => mb_strtolower($brand))
            ->values();

        if ($brands->count() > 10 || $brands->contains(fn (string $brand): bool => mb_strlen($brand) > 80)) {
            throw ValidationException::withMessages([
                'preferred_brands' => 'Choose up to ten brand names, each no longer than 80 characters.',
            ]);
        }
        if ($defaultBasketTargetCents !== null && $defaultBasketTargetCents < 1) {
            throw ValidationException::withMessages([
                'default_basket_target_cents' => 'The household basket target must be above zero.',
            ]);
        }

        return RetailerPurchasePolicy::query()->updateOrCreate(
            ['team_id' => $team->id, 'provider' => $provider],
            [
                'updated_by_user_id' => $user->id,
                'home_brand_preference' => $homeBrandPreference,
                'bulk_preference' => $bulkPreference,
                'organic_preference' => $organicPreference,
                'preferred_brands' => $brands->all(),
                'default_basket_target_cents' => $defaultBasketTargetCents,
            ],
        );
    }
}
