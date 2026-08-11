<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerProvider;
use App\Models\RetailerProductPreference;
use App\Models\Team;
use App\Models\User;

class BuildRetailerPurchasePolicyView
{
    public function __construct(private readonly ResolveEffectiveRetailerPurchasePolicy $resolvePolicy) {}

    /** @return array<string, mixed> */
    public function handle(Team $team, User $user): array
    {
        $effective = $this->resolvePolicy->handleTeam($team);
        $snapshot = $effective['snapshot'];

        return [
            'policy' => [
                'provider' => RetailerProvider::Coles->value,
                'home_brand_preference' => $snapshot['home_brand_preference'],
                'bulk_preference' => $snapshot['bulk_preference'],
                'organic_preference' => $snapshot['organic_preference'],
                'preferred_brands' => $snapshot['preferred_brands'],
                'default_basket_target_cents' => $snapshot['household_basket_target_cents'],
                'fingerprint' => $effective['fingerprint'],
            ],
            'product_preferences' => RetailerProductPreference::query()
                ->where('team_id', $team->id)
                ->where('provider', RetailerProvider::Coles)
                ->whereNull('revoked_at')
                ->orderBy('normalized_name')
                ->orderBy('id')
                ->get()
                ->map(fn (RetailerProductPreference $preference): array => [
                    'id' => $preference->id,
                    'ingredient' => $preference->normalized_name,
                    'form' => $preference->normalized_form,
                    'sku' => $preference->sku,
                    'product_title' => $preference->product_title,
                    'created_at' => $preference->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'can' => ['update' => $user->can('update', $team)],
        ];
    }
}
