<?php

namespace App\Actions\Shopping;

use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResolvePlannedMealIngredients
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    /** @param array<int, array{name: string, quantity?: float|null, unit?: string|null}> $ingredients */
    public function handle(ShoppingList $shoppingList, PlannedMeal $plannedMeal, User $user, array $ingredients, int $expectedRevision): ShoppingList
    {
        if (! $user->can('update', $shoppingList) || $plannedMeal->meal_plan_id !== $shoppingList->meal_plan_id || $plannedMeal->team_id !== $shoppingList->team_id) {
            throw new AuthorizationException('You cannot resolve ingredients for this meal.');
        }

        if ($plannedMeal->getRawOriginal('type') !== PlannedMealType::Custom->value
            || $plannedMeal->getRawOriginal('status') !== PlannedMealStatus::Planned->value
            || $plannedMeal->recipe_version_id !== null) {
            throw ValidationException::withMessages(['planned_meal' => 'Only a planned custom meal without a recipe needs ingredient resolution.']);
        }

        $ingredients = collect($ingredients)
            ->map(fn (array $ingredient): array => [
                'name' => Str::squish($ingredient['name']),
                'quantity' => $ingredient['quantity'] ?? null,
                'unit' => filled($ingredient['unit'] ?? null) ? Str::lower(Str::squish($ingredient['unit'])) : null,
            ])
            ->filter(fn (array $ingredient): bool => $ingredient['name'] !== '')
            ->values();

        if ($ingredients->isEmpty()) {
            throw ValidationException::withMessages(['ingredients' => 'Add at least one ingredient before marking this meal ready.']);
        }

        if ($ingredients->contains(fn (array $ingredient): bool => $ingredient['quantity'] !== null && $ingredient['quantity'] < 0)) {
            throw ValidationException::withMessages(['ingredients' => 'Ingredient quantities cannot be negative.']);
        }

        return DB::transaction(function () use ($shoppingList, $plannedMeal, $user, $ingredients, $expectedRevision): ShoppingList {
            $shoppingList = $this->ensureEditable->handle($shoppingList);
            $existingSources = $shoppingList->items()
                ->where('source_kind', ShoppingListItemSourceKind::PlannedMeal)
                ->whereHas('sources', fn ($query) => $query->where('planned_meal_id', $plannedMeal->id)->whereNull('recipe_ingredient_id'))
                ->get();

            foreach ($existingSources as $item) {
                $item->delete();
            }

            $position = ($shoppingList->items()->max('position') ?? 0) + 1;

            foreach ($ingredients as $ingredient) {
                $item = $shoppingList->items()->create([
                    'team_id' => $shoppingList->team_id,
                    'created_by_user_id' => $user->id,
                    'source_kind' => ShoppingListItemSourceKind::PlannedMeal,
                    'name' => $ingredient['name'],
                    'normalized_name' => Str::lower($ingredient['name']),
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit'],
                    'included' => true,
                    'position' => $position++,
                ]);
                $item->sources()->create([
                    'team_id' => $shoppingList->team_id,
                    'planned_meal_id' => $plannedMeal->id,
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit'],
                ]);
            }

            $shoppingList->mealResolutions()->updateOrCreate(
                ['planned_meal_id' => $plannedMeal->id],
                ['team_id' => $shoppingList->team_id, 'resolved_by_user_id' => $user->id, 'resolved_at' => now()],
            );
            $shoppingList->update(['status' => ShoppingListStatus::Draft, 'completed_at' => null]);
            $this->recordRevision->handle($shoppingList, $user, 'Added ingredients for '.$plannedMeal->title, $expectedRevision);

            return $shoppingList->refresh();
        });
    }
}
