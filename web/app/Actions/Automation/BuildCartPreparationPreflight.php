<?php

namespace App\Actions\Automation;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Enums\ConstraintKind;
use App\Models\Constraint;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;

class BuildCartPreparationPreflight
{
    public function __construct(private readonly MealPlanSafetyContext $safetyContext) {}

    /** @return array<string, mixed> */
    public function handle(ShoppingList $shoppingList, ShoppingListRevision $revision): array
    {
        $snapshotItems = $revision->snapshot['items'] ?? null;
        $items = collect(is_array($snapshotItems) ? $snapshotItems : [])
            ->filter(fn ($item): bool => is_array($item)
                && (bool) ($item['included'] ?? false)
                && ! (bool) ($item['in_pantry'] ?? false));
        $participantIds = $shoppingList->mealPlan->slots()
            ->with('participants:id')
            ->get()
            ->flatMap->participants
            ->pluck('id')
            ->unique()
            ->values();
        $constraints = Constraint::query()
            ->where('team_id', $shoppingList->team_id)
            ->where(fn ($query) => $query
                ->whereNull('person_id')
                ->when($participantIds->isNotEmpty(), fn ($query) => $query->orWhereIn('person_id', $participantIds)))
            ->with('person:id,name')
            ->orderBy('id')
            ->get();
        $strictKinds = [
            ConstraintKind::Allergy,
            ConstraintKind::Medical,
            ConstraintKind::Dietary,
            ConstraintKind::Religious,
        ];
        $requiresExactMatches = $constraints->contains(
            fn (Constraint $constraint): bool => in_array($constraint->kind, $strictKinds, true),
        );
        $automaticItems = $items
            ->filter(function (array $item) use ($requiresExactMatches): bool {
                $match = $item['product_match'] ?? null;

                return ! is_array($match)
                    || ($requiresExactMatches && (
                        ! filled($match['external_id'] ?? null)
                        || ! filled($match['product_url'] ?? null)
                    ));
            })
            ->values();

        return [
            'total_items' => $items->count(),
            'matched_items' => $items->count() - $automaticItems->count(),
            'automatic_search_items' => $automaticItems->count(),
            'automatic_search_item_names' => $automaticItems
                ->pluck('name')
                ->filter(fn ($name): bool => is_string($name) && $name !== '')
                ->take(12)
                ->values()
                ->all(),
            'requires_exact_matches' => $requiresExactMatches,
            'can_prepare' => ! $requiresExactMatches || $automaticItems->isEmpty(),
            'safety_fingerprint' => $this->safetyContext->fingerprint($shoppingList->mealPlan),
            'constraints' => $constraints->map(fn (Constraint $constraint): array => [
                'id' => $constraint->id,
                'kind' => $constraint->kind->value,
                'subject' => $constraint->subject,
                'severity' => $constraint->severity,
                'person' => $constraint->person?->name,
            ])->values()->all(),
        ];
    }
}
