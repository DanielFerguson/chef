<?php

namespace App\Actions\Shopping;

use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListItemSourceKind;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ValidateShoppingListDraft
{
    public function __construct(
        private readonly ShoppingItemIdentity $identity,
        private readonly ShoppingRequirementQuantity $quantities,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function handle(
        ShoppingListDraft $draft,
        ShoppingListDraftRequest $request,
        ShoppingListItemSourceKind $sourceKind,
    ): array {
        $validCategories = array_map(
            fn (ShoppingListItemCategory $category): string => $category->value,
            ShoppingListItemCategory::cases(),
        );
        $validated = Validator::make($draft->toArray(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.category' => ['required', 'string', 'in:'.implode(',', $validCategories)],
            'items.*.source_requirement_ids' => ['required', 'array', 'min:1'],
            'items.*.source_requirement_ids.*' => ['required', 'integer'],
        ])->validate();
        $knownRequirementIds = array_map('intval', array_keys($request->sources));
        sort($knownRequirementIds);
        $knownMealIds = collect($request->meals)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $coveredRequirementIds = [];
        $referencedMealIds = [];
        $seenIdentities = [];
        $requirements = [];

        foreach ($validated['items'] as $index => $item) {
            $canonicalKey = $this->identity->key($item['name']);

            if ($canonicalKey === '' || $this->identity->isTapWater($item['name'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.name" => 'Shopping-list items must have a useful non-water ingredient name.',
                ]);
            }

            if (isset($seenIdentities[$canonicalKey])) {
                throw ValidationException::withMessages([
                    "items.{$index}.name" => 'Shopping-list item identities must be unique after canonicalisation.',
                ]);
            }

            $sourceIds = array_map('intval', $item['source_requirement_ids']);

            if (count($sourceIds) !== count(array_unique($sourceIds)) || array_diff($sourceIds, $knownRequirementIds) !== []) {
                throw ValidationException::withMessages([
                    "items.{$index}.source_requirement_ids" => 'Each source requirement may be referenced once and must come from the supplied request.',
                ]);
            }

            $sources = array_map(fn (int $sourceId): array => $request->sources[$sourceId], $sourceIds);
            $sourceIdentities = collect($sources)->pluck('canonical_key')->unique()->values();

            if ($sourceIdentities->count() !== 1 || $sourceIdentities->first() !== $canonicalKey) {
                throw ValidationException::withMessages([
                    "items.{$index}.source_requirement_ids" => 'Only purchase-equivalent ingredient requirements may be grouped together.',
                ]);
            }

            $combined = $this->quantities->combine($sources);
            $mealIds = collect($sources)->pluck('planned_meal_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $ingredientIds = collect($sources)->pluck('ingredient_id')->filter()->unique()->values();
            $sourceSignature = collect($sources)
                ->map(fn (array $source): string => $source['planned_meal_id'].':'.$source['recipe_ingredient_id'])
                ->sort()
                ->implode('|');
            $normalisedName = Str::of($item['name'])->squish()->lower()->toString();
            $seenIdentities[$canonicalKey] = true;
            $coveredRequirementIds = [...$coveredRequirementIds, ...$sourceIds];
            $referencedMealIds = [...$referencedMealIds, ...$mealIds];
            $requirements[$canonicalKey] = [
                'ingredient_id' => $ingredientIds->count() === 1 ? $ingredientIds->first() : null,
                'name' => Str::of($item['name'])->squish()->toString(),
                'normalized_name' => $normalisedName,
                'quantity' => $combined['quantity'],
                'unit' => $combined['unit'],
                'optional' => collect($sources)->every(fn (array $source): bool => $source['optional']),
                'category' => $item['category'],
                'source_kind' => $sourceKind,
                'sources' => array_map(fn (array $source): array => [
                    'team_id' => $source['team_id'],
                    'planned_meal_id' => $source['planned_meal_id'],
                    'recipe_ingredient_id' => $source['recipe_ingredient_id'],
                    'quantity' => $source['quantity'],
                    'unit' => $source['unit'],
                ], $sources),
                '_canonical_key' => $canonicalKey,
                '_source_signature' => $sourceSignature,
            ];
        }

        sort($coveredRequirementIds);
        $referencedMealIds = collect($referencedMealIds)->unique()->sort()->values()->all();

        if ($coveredRequirementIds !== $knownRequirementIds) {
            throw ValidationException::withMessages([
                'items' => 'Every non-water source requirement must appear exactly once.',
            ]);
        }

        if ($referencedMealIds !== $knownMealIds) {
            throw ValidationException::withMessages([
                'items' => 'Every supplied meal must be referenced by at least one shopping-list item.',
            ]);
        }

        return $requirements;
    }
}
