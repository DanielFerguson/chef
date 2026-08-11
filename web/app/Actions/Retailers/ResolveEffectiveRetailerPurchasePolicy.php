<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Models\MealPlan;
use App\Models\MealPlanPurchasePreference;
use App\Models\RetailerPurchasePolicy;
use App\Models\Team;

class ResolveEffectiveRetailerPurchasePolicy
{
    /**
     * @return array{
     *     snapshot: array<string, mixed>,
     *     fingerprint: string,
     *     basket_target_cents: int|null
     * }
     */
    public function handle(MealPlan $mealPlan, RetailerProvider $provider = RetailerProvider::Coles): array
    {
        return $this->handleTeam($mealPlan->team, $provider, $mealPlan);
    }

    /**
     * @return array{
     *     snapshot: array<string, mixed>,
     *     fingerprint: string,
     *     basket_target_cents: int|null
     * }
     */
    public function handleTeam(
        Team $team,
        RetailerProvider $provider = RetailerProvider::Coles,
        ?MealPlan $mealPlan = null,
    ): array {
        $household = RetailerPurchasePolicy::query()
            ->where('team_id', $team->id)
            ->where('provider', $provider)
            ->first();
        $plan = $mealPlan === null
            ? null
            : MealPlanPurchasePreference::query()
                ->where('meal_plan_id', $mealPlan->id)
                ->where('provider', $provider)
                ->first();
        $householdTarget = $household instanceof RetailerPurchasePolicy
            ? $household->default_basket_target_cents
            : null;
        $planTarget = $plan instanceof MealPlanPurchasePreference
            ? $plan->basket_target_cents
            : null;
        $basketTarget = $planTarget ?? $householdTarget;
        $homeBrandPreference = $household instanceof RetailerPurchasePolicy
            ? $household->home_brand_preference
            : RetailerHomeBrandPreference::Allow;
        $bulkPreference = $household instanceof RetailerPurchasePolicy
            ? $household->bulk_preference
            : RetailerBulkPreference::Avoid;
        $organicPreference = $household instanceof RetailerPurchasePolicy
            ? $household->organic_preference
            : RetailerOrganicPreference::NoPreference;
        $preferredBrands = $household instanceof RetailerPurchasePolicy
            ? $household->preferred_brands
            : [];
        $snapshot = [
            'provider' => $provider->value,
            'home_brand_preference' => $homeBrandPreference->value,
            'bulk_preference' => $bulkPreference->value,
            'organic_preference' => $organicPreference->value,
            'preferred_brands' => $preferredBrands,
            'household_basket_target_cents' => $householdTarget,
            'plan_basket_target_cents' => $planTarget,
            'effective_basket_target_source' => $planTarget !== null
                ? 'plan'
                : ($householdTarget !== null ? 'household' : null),
        ];

        return [
            'snapshot' => $snapshot,
            'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'basket_target_cents' => $basketTarget,
        ];
    }
}
