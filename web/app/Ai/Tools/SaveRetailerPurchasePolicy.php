<?php

namespace App\Ai\Tools;

use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Actions\Retailers\UpdateRetailerPurchasePolicy;
use App\Actions\Retailers\ValidateExplicitRetailerPreferenceEvidence;
use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SaveRetailerPurchasePolicy implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly UpdateRetailerPurchasePolicy $updatePolicy,
        private readonly ResolveEffectiveRetailerPurchasePolicy $resolvePolicy,
        private readonly ValidateExplicitRetailerPreferenceEvidence $validateEvidence,
    ) {}

    public function description(): Stringable|string
    {
        return 'Save an explicit household-wide Coles purchasing policy for future plans. Call only when the current user says always, prefer, or next time. Never infer a durable policy from one accepted basket.';
    }

    public function handle(Request $request): Stringable|string
    {
        $current = $this->resolvePolicy->handleTeam($this->team)['snapshot'];
        $currentHomeBrandPreference = RetailerHomeBrandPreference::from((string) $current['home_brand_preference']);
        $currentBulkPreference = RetailerBulkPreference::from((string) $current['bulk_preference']);
        $currentOrganicPreference = RetailerOrganicPreference::from((string) $current['organic_preference']);
        $currentPreferredBrands = array_values(array_filter(
            (array) $current['preferred_brands'],
            fn (mixed $brand): bool => is_string($brand),
        ));
        $currentDefaultBasketTargetCents = is_int($current['household_basket_target_cents'])
            ? $current['household_basket_target_cents']
            : null;
        $evidenceQuote = $request->string('evidence_quote')->toString();
        $homeBrandPreference = $request->exists('home_brand_preference')
            ? RetailerHomeBrandPreference::from($request->string('home_brand_preference')->toString())
            : $currentHomeBrandPreference;
        $bulkPreference = $request->exists('bulk_preference')
            ? RetailerBulkPreference::from($request->string('bulk_preference')->toString())
            : $currentBulkPreference;
        $organicPreference = $request->exists('organic_preference')
            ? RetailerOrganicPreference::from($request->string('organic_preference')->toString())
            : $currentOrganicPreference;
        $preferredBrands = $request->exists('preferred_brands')
            ? array_values($request->array('preferred_brands'))
            : $currentPreferredBrands;
        if ($this->normalizedBrandSet($preferredBrands) === $this->normalizedBrandSet($currentPreferredBrands)) {
            $preferredBrands = $currentPreferredBrands;
        }
        $defaultBasketTargetCents = $request->exists('default_basket_target_cents')
            ? ($request->integer('default_basket_target_cents') ?: null)
            : $currentDefaultBasketTargetCents;

        $changed = false;
        if ($homeBrandPreference !== $currentHomeBrandPreference) {
            $changed = true;
            $this->validateEvidence->handleForSubjectValue(
                $this->sourceMessage,
                $evidenceQuote,
                ['home brand', 'home-brand', 'store brand', 'Coles brand', 'Coles own brand'],
                match ($homeBrandPreference) {
                    RetailerHomeBrandPreference::Allow => ['allow', 'okay', 'ok', 'fine', 'can use', 'do not mind', "don't mind"],
                    RetailerHomeBrandPreference::Prefer => ['prefer', 'preferred', 'always choose', 'prioritise', 'prioritize'],
                    RetailerHomeBrandPreference::Avoid => ['avoid', 'never', 'do not', "don't", 'no home brand', 'no store brand'],
                },
                'home-brand',
            );
        }
        if ($bulkPreference !== $currentBulkPreference) {
            $changed = true;
            $this->validateEvidence->handleForSubjectValue(
                $this->sourceMessage,
                $evidenceQuote,
                ['bulk', 'large pack', 'larger pack', 'family pack'],
                match ($bulkPreference) {
                    RetailerBulkPreference::Allow => ['allow', 'okay', 'ok', 'fine', 'can use', 'do not mind', "don't mind"],
                    RetailerBulkPreference::Avoid => ['avoid', 'never', 'do not', "don't", 'no bulk', 'no large pack', 'no family pack'],
                },
                'bulk-pack',
            );
        }
        if ($organicPreference !== $currentOrganicPreference) {
            $changed = true;
            $this->validateEvidence->handleForSubjectValue(
                $this->sourceMessage,
                $evidenceQuote,
                ['organic'],
                match ($organicPreference) {
                    RetailerOrganicPreference::NoPreference => ['no preference', 'do not prefer', "don't prefer", 'does not matter', "doesn't matter", 'any'],
                    RetailerOrganicPreference::Prefer => ['prefer', 'preferred', 'always choose', 'prioritise', 'prioritize'],
                },
                'organic-product',
            );
        }
        if ($preferredBrands !== $currentPreferredBrands) {
            $changed = true;
            $this->validatePreferredBrandChanges(
                $evidenceQuote,
                $currentPreferredBrands,
                $preferredBrands,
            );
        }
        if ($defaultBasketTargetCents !== $currentDefaultBasketTargetCents) {
            $changed = true;
            if ($defaultBasketTargetCents === null) {
                $this->validateEvidence->handleForSubjectValue(
                    $this->sourceMessage,
                    $evidenceQuote,
                    ['basket target', 'budget', 'spend limit'],
                    ['clear', 'remove', 'no budget', 'without a budget', 'do not use', "don't use"],
                    'basket-target',
                );
            } else {
                $this->validateEvidence->handleForAmountCents(
                    $this->sourceMessage,
                    $evidenceQuote,
                    $defaultBasketTargetCents,
                    'basket-target',
                );
            }
        }

        if (! $changed) {
            throw ValidationException::withMessages([
                'policy' => 'No household grocery policy settings changed.',
            ]);
        }

        $policy = $this->updatePolicy->handle(
            $this->team,
            $this->actor,
            RetailerProvider::Coles,
            $homeBrandPreference,
            $bulkPreference,
            $organicPreference,
            $preferredBrands,
            $defaultBasketTargetCents,
        );

        return json_encode([
            'saved' => true,
            'policy' => [
                'home_brand_preference' => $policy->home_brand_preference->value,
                'bulk_preference' => $policy->bulk_preference->value,
                'organic_preference' => $policy->organic_preference->value,
                'preferred_brands' => $policy->preferred_brands,
                'default_basket_target_cents' => $policy->default_basket_target_cents,
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $currentBrands
     * @param  list<string>  $preferredBrands
     */
    private function validatePreferredBrandChanges(
        string $evidenceQuote,
        array $currentBrands,
        array $preferredBrands,
    ): void {
        $normalize = fn (string $brand): string => mb_strtolower(trim($brand));
        $currentByName = collect($currentBrands)->keyBy($normalize);
        $preferredByName = collect($preferredBrands)->keyBy($normalize);
        $added = array_values($preferredByName->diffKeys($currentByName)->values()->all());
        $removed = array_values($currentByName->diffKeys($preferredByName)->values()->all());

        if ($added !== []) {
            $this->validateEvidence->handleForEveryTerm(
                $this->sourceMessage,
                $evidenceQuote,
                $added,
                'preferred brand',
            );
            foreach ($added as $brand) {
                $this->validateEvidence->handleForSubjectValue(
                    $this->sourceMessage,
                    $evidenceQuote,
                    [$brand],
                    ['prefer', 'preferred', 'always choose', 'add', 'save', 'remember'],
                    'preferred-brand',
                );
            }
        }

        if ($removed === []) {
            return;
        }

        $this->validateEvidence->handleForEveryTerm(
            $this->sourceMessage,
            $evidenceQuote,
            $removed,
            'removed brand',
        );
        foreach ($removed as $brand) {
            $this->validateEvidence->handleForSubjectValue(
                $this->sourceMessage,
                $evidenceQuote,
                [$brand],
                ['remove', 'stop', 'avoid', 'drop', 'clear', 'no longer', 'instead of', 'do not prefer', "don't prefer"],
                'removed-brand',
            );
        }
    }

    /** @param list<string> $brands
     * @return list<string>
     */
    private function normalizedBrandSet(array $brands): array
    {
        return array_values(collect($brands)
            ->map(fn (string $brand): string => mb_strtolower(trim($brand)))
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'home_brand_preference' => $schema->string()->description('Optional: allow, prefer, or avoid. Omit unless the current user explicitly changes this setting.'),
            'bulk_preference' => $schema->string()->description('Optional: allow or avoid. Omit unless the current user explicitly changes this setting.'),
            'organic_preference' => $schema->string()->description('Optional: no_preference or prefer. Omit unless the current user explicitly changes this setting.'),
            'preferred_brands' => $schema->array()->items($schema->string())->description('Optional ordered explicit preferred brand names. Omit unless the current user explicitly changes saved brands; use an empty array only when they explicitly clear them.'),
            'default_basket_target_cents' => $schema->integer()->description('Optional explicit default basket target in Australian cents.'),
            'evidence_quote' => $schema->string()->description('Exact words in the current user message containing always, prefer, or next time.')->required(),
        ];
    }
}
