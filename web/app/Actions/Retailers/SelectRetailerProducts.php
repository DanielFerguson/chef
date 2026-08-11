<?php

namespace App\Actions\Retailers;

use App\Ai\Contracts\RetailerProductRanker;
use App\Ai\Data\RetailerProductRankingRequest;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerSelectionMethod;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\RetailerProductCandidate;
use App\Models\RetailerProductPreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SelectRetailerProducts
{
    public function __construct(
        private readonly RetailerProductRanker $ranker,
        private readonly ChooseBalancedPack $chooseBalancedPack,
        private readonly ResolveEffectiveRetailerPurchasePolicy $resolvePurchasePolicy,
    ) {}

    public function handle(GroceryPlan $groceryPlan): bool
    {
        $groceryPlan->load([
            'mealPlan',
            'requirements.candidates',
            'requirements.selection',
        ]);
        $policy = $groceryPlan->purchase_policy_snapshot;
        if ($policy === null) {
            $effective = $this->resolvePurchasePolicy->handle($groceryPlan->mealPlan);
            $policy = $effective['snapshot'];
            $groceryPlan->update([
                'purchase_policy_snapshot' => $policy,
                'purchase_policy_fingerprint' => $effective['fingerprint'],
                'effective_basket_target_cents' => $effective['basket_target_cents'],
            ]);
        }

        $requirements = $groceryPlan->requirements;
        $eligibleByRequirement = $requirements->mapWithKeys(
            fn (GroceryRequirement $requirement): array => [
                $requirement->id => $requirement->candidates
                    ->where('status', RetailerCandidateStatus::Eligible)
                    ->values(),
            ],
        );
        $blocked = $requirements->filter(
            fn (GroceryRequirement $requirement): bool => $eligibleByRequirement[$requirement->id]->isEmpty(),
        );

        if ($blocked->isNotEmpty()) {
            DB::transaction(function () use ($groceryPlan, $blocked): void {
                GroceryRequirement::query()
                    ->whereKey($blocked->modelKeys())
                    ->update(['status' => GroceryRequirementStatus::NeedsProduct->value]);
                $groceryPlan->update(['status' => GroceryPlanStatus::NeedsProduct]);
                $groceryPlan->basketRuns()
                    ->whereIn('status', collect(BasketRunStatus::cases())
                        ->filter(fn (BasketRunStatus $status): bool => $status->isActive())
                        ->pluck('value'))
                    ->update([
                        'status' => config('retailer.features.ai_recovery', false)
                            ? BasketRunStatus::PreparingResolution->value
                            : BasketRunStatus::NeedsProduct->value,
                        'attention_kind' => MealPlanAdjustmentKind::ProductUnavailable->value,
                        'failure_code' => config('retailer.features.ai_recovery', false)
                            ? null
                            : 'no_valid_candidate',
                        'failure_message' => config('retailer.features.ai_recovery', false)
                            ? null
                            : 'Chef could not find a product that passed every required check.',
                    ]);
            });

            return false;
        }

        /** @var array<int, array<string, mixed>> $decisions */
        $decisions = [];
        $ambiguous = [];

        foreach ($requirements as $requirement) {
            $eligible = $eligibleByRequirement[$requirement->id];
            $preference = $this->savedPreference($requirement);
            $preferredCandidate = $preference === null
                ? null
                : $eligible->firstWhere('sku', $preference->sku);

            if ($preferredCandidate instanceof RetailerProductCandidate) {
                $decisions[$requirement->id] = [
                    'method' => RetailerSelectionMethod::SavedPreference,
                    'candidate_ids' => [$preferredCandidate->id],
                    'confidence' => 1.0,
                    'semantic_tier' => 0,
                    'skip_policy' => true,
                ];

                continue;
            }

            if ($eligible->pluck('semantic_key')->unique()->count() === 1) {
                $decisions[$requirement->id] = [
                    'method' => RetailerSelectionMethod::Deterministic,
                    'candidate_ids' => $eligible->pluck('id')->sort()->values()->all(),
                    'confidence' => 1.0,
                    'semantic_tier' => 1,
                    'skip_policy' => false,
                ];

                continue;
            }

            $ambiguous[] = $this->rankingRequirement($requirement, $eligible);
        }

        if ($ambiguous !== []) {
            $ranking = $this->ranker->rank(new RetailerProductRankingRequest(
                teamId: $groceryPlan->team_id,
                groceryPlanId: $groceryPlan->id,
                requirements: $ambiguous,
            ));
            $rankingsByRequirement = collect($ranking->rankings)->keyBy('requirement_id');

            if ($rankingsByRequirement->count() !== count($ambiguous)) {
                throw ValidationException::withMessages([
                    'rankings' => 'The product ranking must cover every ambiguous grocery requirement exactly once.',
                ]);
            }

            foreach ($ambiguous as $rankingRequirement) {
                $requirementId = $rankingRequirement['requirement_id'];
                $result = $rankingsByRequirement->get($requirementId);
                if (! is_array($result)) {
                    throw ValidationException::withMessages([
                        'rankings' => 'The product ranking returned an invalid result.',
                    ]);
                }

                $tiers = $this->resultTiers($result);
                $expectedIds = array_map(
                    fn (array $candidate): int => (int) $candidate['candidate_id'],
                    $rankingRequirement['candidates'],
                );
                sort($expectedIds);
                $actualIds = array_merge(...array_map(
                    fn (array $tier): array => $tier['candidate_ids'],
                    $tiers,
                ));
                $sortedActualIds = $actualIds;
                sort($sortedActualIds);

                if ($tiers === []
                    || collect($tiers)->contains(fn (array $tier): bool => $tier['candidate_ids'] === [])
                    || count($actualIds) !== count(array_unique($actualIds))
                    || $sortedActualIds !== $expectedIds) {
                    throw ValidationException::withMessages([
                        'rankings' => 'The product ranking returned invalid semantic tiers or candidate identifiers.',
                    ]);
                }

                $decisions[$requirementId] = [
                    'method' => RetailerSelectionMethod::AiRanked,
                    'candidate_ids' => $tiers[0]['candidate_ids'],
                    'confidence' => max(0, min(1, (float) ($result['confidence'] ?? 0.5))),
                    'semantic_tier' => 1,
                    'skip_policy' => false,
                ];
            }
        }

        DB::transaction(function () use ($groceryPlan, $requirements, $eligibleByRequirement, $decisions, $policy): void {
            $run = $groceryPlan->basketRuns()->latest('id')->lockForUpdate()->first();
            if ($run !== null) {
                $run->update(['status' => BasketRunStatus::SelectingProducts]);
            }

            foreach ($requirements as $requirement) {
                $decision = $decisions[$requirement->id];
                $tierCandidates = $eligibleByRequirement[$requirement->id]
                    ->whereIn('id', $decision['candidate_ids'])
                    ->values();
                if ($tierCandidates->count() !== count($decision['candidate_ids'])) {
                    throw ValidationException::withMessages([
                        'rankings' => 'A semantically selected product candidate is no longer available.',
                    ]);
                }

                if ($decision['skip_policy']) {
                    $policyResult = [
                        'candidates' => $tierCandidates,
                        'decisions' => ['saved_product_preference:applied'],
                        'exceptions' => [],
                    ];
                } else {
                    $policyResult = $this->applyPolicy($requirement, $tierCandidates, $policy);
                }
                $pack = $this->chooseBalancedPack->handle($requirement, $policyResult['candidates']);
                $selectionChecksum = hash('sha256', json_encode([
                    'requirement_fingerprint' => $requirement->fingerprint,
                    'candidate_fingerprint' => $pack['candidate']->fingerprint,
                    'pack_count' => $pack['pack_count'],
                    'total_price_cents' => $pack['total_price_cents'],
                    'purchase_policy_fingerprint' => $groceryPlan->purchase_policy_fingerprint,
                ], JSON_THROW_ON_ERROR));
                $reasoning = ($decision['method'] === RetailerSelectionMethod::SavedPreference
                    ? 'Chef reused the household’s explicitly saved product preference. '
                    : 'Chef selected from semantic suitability tier '.$decision['semantic_tier'].'. ')
                    .$pack['reasoning'];
                $selection = $requirement->selection()->updateOrCreate([], [
                    'team_id' => $requirement->team_id,
                    'retailer_product_candidate_id' => $pack['candidate']->id,
                    'method' => $decision['method'],
                    'confidence' => $decision['confidence'],
                    'low_confidence' => $decision['confidence'] < 0.65,
                    'semantic_tier' => $decision['semantic_tier'],
                    'reasoning' => $reasoning,
                    'policy_decisions' => $policyResult['decisions'],
                    'material_exceptions' => $policyResult['exceptions'],
                    'pack_count' => $pack['pack_count'],
                    'required_quantity' => $requirement->quantity,
                    'total_quantity' => $pack['total_quantity'],
                    'waste_quantity' => $pack['waste_quantity'],
                    'total_price_cents' => $pack['total_price_cents'],
                    'selection_checksum' => $selectionChecksum,
                    'selected_at' => now(),
                ]);
                $requirement->update(['status' => GroceryRequirementStatus::Selected]);

                if ($run !== null) {
                    $run->items()->updateOrCreate(
                        ['grocery_requirement_id' => $requirement->id],
                        [
                            'team_id' => $run->team_id,
                            'retailer_product_selection_id' => $selection->id,
                            'sku' => $pack['candidate']->sku,
                            'product_title' => $pack['candidate']->title,
                            'absolute_quantity' => $pack['pack_count'],
                            'unit_price_cents' => $pack['candidate']->price_cents,
                            'line_price_cents' => $pack['total_price_cents'],
                            'pack_reasoning' => $selection->reasoning,
                        ],
                    );
                }
            }

            if ($run !== null) {
                $run->update([
                    'status' => BasketRunStatus::RevalidatingProducts,
                    'target_checksum' => hash('sha256', $run->items()
                        ->orderBy('sku')
                        ->get(['sku', 'absolute_quantity'])
                        ->toJson()),
                    'chef_subtotal_cents' => $run->items()->sum('line_price_cents'),
                    'failure_code' => null,
                    'failure_message' => null,
                ]);
            }
        });

        return true;
    }

    private function savedPreference(GroceryRequirement $requirement): ?RetailerProductPreference
    {
        return RetailerProductPreference::query()
            ->where('team_id', $requirement->team_id)
            ->where('provider', RetailerProvider::Coles)
            ->where('normalized_name', $requirement->normalized_name)
            ->where('normalized_form', $requirement->normalized_form)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  Collection<int, RetailerProductCandidate>  $candidates
     * @param  array<string, mixed>  $policy
     * @return array{candidates: Collection<int, RetailerProductCandidate>, decisions: list<string>, exceptions: list<string>}
     */
    private function applyPolicy(GroceryRequirement $requirement, Collection $candidates, array $policy): array
    {
        $options = $candidates->mapWithKeys(function (RetailerProductCandidate $candidate) use ($requirement): array {
            $pack = $this->chooseBalancedPack->handle($requirement, collect([$candidate]));

            return [$candidate->id => $pack];
        });
        $cheapestPrice = (int) $options->min('total_price_cents');
        $withinCeiling = $candidates->filter(
            fn (RetailerProductCandidate $candidate): bool => $options[$candidate->id]['total_price_cents'] * 100 <= $cheapestPrice * 115,
        )->values();
        $current = $withinCeiling;
        $decisions = [];
        $exceptions = [];

        $preferredBrands = array_values(array_filter(
            $policy['preferred_brands'] ?? [],
            'is_string',
        ));
        if ($preferredBrands !== []) {
            $appliedBrand = collect($preferredBrands)->first(function (string $brand) use ($current): bool {
                return $current->contains(
                    fn (RetailerProductCandidate $candidate): bool => mb_strtolower((string) $candidate->brand) === mb_strtolower($brand),
                );
            });
            if (is_string($appliedBrand)) {
                $current = $current->filter(
                    fn (RetailerProductCandidate $candidate): bool => mb_strtolower((string) $candidate->brand) === mb_strtolower($appliedBrand),
                )->values();
                $decisions[] = 'preferred_brand:'.$appliedBrand;
            } elseif ($candidates->contains(fn (RetailerProductCandidate $candidate): bool => collect($preferredBrands)
                ->contains(fn (string $brand): bool => mb_strtolower((string) $candidate->brand) === mb_strtolower($brand)))) {
                $exceptions[] = 'preferred_brand:not_applied_over_15_percent';
            }
        }

        $homeBrandPreference = (string) ($policy['home_brand_preference'] ?? 'allow');
        $decisions[] = 'home_brand_preference:'.$homeBrandPreference;
        if (in_array($homeBrandPreference, ['prefer', 'avoid'], true)) {
            $desired = $homeBrandPreference === 'prefer';
            $matches = $current->filter(
                fn (RetailerProductCandidate $candidate): bool => $candidate->is_home_brand === $desired,
            )->values();
            if ($matches->isNotEmpty()) {
                $current = $matches;
            } elseif ($candidates->contains(fn (RetailerProductCandidate $candidate): bool => $candidate->is_home_brand === $desired)) {
                $exceptions[] = 'home_brand_preference:not_applied_over_15_percent';
            }
        }

        $organicPreference = (string) ($policy['organic_preference'] ?? 'no_preference');
        $decisions[] = 'organic_preference:'.$organicPreference;
        if ($organicPreference === 'prefer') {
            $organic = $current->filter(
                fn (RetailerProductCandidate $candidate): bool => $candidate->is_organic === true,
            )->values();
            if ($organic->isNotEmpty()) {
                $current = $organic;
            } elseif ($candidates->contains(fn (RetailerProductCandidate $candidate): bool => $candidate->is_organic === true)) {
                $exceptions[] = 'organic_preference:not_applied_over_15_percent';
            }
        }

        $bulkPreference = (string) ($policy['bulk_preference'] ?? 'avoid');
        if ($requirement->quantity_unknown || $requirement->quantity === null) {
            $decisions[] = 'quantity_unknown:smallest_valid_pack';
        } else {
            $decisions[] = 'bulk_preference:'.$bulkPreference;
        }
        if ($bulkPreference === 'avoid' && ! $requirement->quantity_unknown && $requirement->quantity !== null) {
            $nonBulk = $current->filter(
                fn (RetailerProductCandidate $candidate): bool => $options[$candidate->id]['total_quantity'] <= $requirement->quantity * 2,
            )->values();
            if ($nonBulk->isNotEmpty()) {
                $current = $nonBulk;
            } elseif ($candidates->contains(
                fn (RetailerProductCandidate $candidate): bool => $options[$candidate->id]['total_quantity'] <= $requirement->quantity * 2,
            )) {
                $exceptions[] = 'bulk_preference:not_applied_over_15_percent';
            } else {
                $exceptions[] = 'bulk_preference:no_non_bulk_option';
            }
        }

        return [
            'candidates' => $current->isEmpty() ? $withinCeiling : $current,
            'decisions' => $decisions,
            'exceptions' => array_values(array_unique($exceptions)),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{candidate_ids: list<int>}>
     */
    private function resultTiers(array $result): array
    {
        if (is_array($result['tiers'] ?? null)) {
            return array_values(array_map(
                fn (array $tier): array => [
                    'candidate_ids' => array_values(array_map(
                        fn ($candidateId): int => (int) $candidateId,
                        is_array($tier['candidate_ids'] ?? null) ? $tier['candidate_ids'] : [],
                    )),
                ],
                $result['tiers'],
            ));
        }

        return array_values(array_map(
            fn ($candidateId): array => ['candidate_ids' => [(int) $candidateId]],
            is_array($result['ranked_candidate_ids'] ?? null) ? $result['ranked_candidate_ids'] : [],
        ));
    }

    /**
     * @param  Collection<int, RetailerProductCandidate>  $candidates
     * @return array{
     *     requirement_id: int,
     *     name: string,
     *     form: string|null,
     *     quantity: float|null,
     *     unit: string|null,
     *     quantity_unknown: bool,
     *     candidates: list<array{
     *         candidate_id: int,
     *         sku: string,
     *         title: string,
     *         brand: string|null,
     *         is_home_brand: bool|null,
     *         is_organic: bool|null,
     *         semantic_key: string,
     *         pack_quantity: float|null,
     *         pack_unit: string|null,
     *         price_cents: int|null
     *     }>
     * }
     */
    private function rankingRequirement(GroceryRequirement $requirement, Collection $candidates): array
    {
        $candidatePayloads = [];
        foreach ($candidates->sortBy('id')->values() as $candidate) {
            $candidatePayloads[] = [
                'candidate_id' => $candidate->id,
                'sku' => $candidate->sku,
                'title' => $candidate->title,
                'brand' => $candidate->brand,
                'is_home_brand' => $candidate->is_home_brand,
                'is_organic' => $candidate->is_organic,
                'semantic_key' => $candidate->semantic_key,
                'pack_quantity' => $candidate->pack_quantity,
                'pack_unit' => $candidate->pack_unit,
                'price_cents' => $candidate->price_cents,
            ];
        }

        return [
            'requirement_id' => $requirement->id,
            'name' => $requirement->display_name,
            'form' => $requirement->normalized_form,
            'quantity' => $requirement->quantity,
            'unit' => $requirement->unit,
            'quantity_unknown' => $requirement->quantity_unknown,
            'candidates' => $candidatePayloads,
        ];
    }
}
