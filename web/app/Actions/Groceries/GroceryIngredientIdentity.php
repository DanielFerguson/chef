<?php

namespace App\Actions\Groceries;

use Illuminate\Support\Str;

class GroceryIngredientIdentity
{
    /**
     * @return array{
     *     normalized_name: string,
     *     normalized_form: string|null,
     *     quantity: float|null,
     *     unit: string|null,
     *     fingerprint: string
     * }
     */
    public function resolve(
        string $name,
        ?string $preparation,
        ?float $quantity,
        ?string $unit,
    ): array {
        $normalizedName = Str::of($name)->squish()->lower()->toString();
        $normalizedForm = $this->normalizedForm($preparation);
        [$normalizedQuantity, $normalizedUnit] = $this->normalizedQuantity($quantity, $unit);
        $fingerprint = hash('sha256', implode('|', [
            $normalizedName,
            $normalizedForm ?? '',
            $normalizedUnit ?? '',
        ]));

        return [
            'normalized_name' => $normalizedName,
            'normalized_form' => $normalizedForm,
            'quantity' => $normalizedQuantity,
            'unit' => $normalizedUnit,
            'fingerprint' => $fingerprint,
        ];
    }

    public function isWater(string $name): bool
    {
        return in_array(
            Str::of($name)->squish()->lower()->toString(),
            [
                'water',
                'tap water',
                'cold water',
                'hot water',
                'warm water',
                'boiling water',
                'ice',
                'ice cubes',
            ],
            true,
        );
    }

    /** @return array{float|null, string|null} */
    private function normalizedQuantity(?float $quantity, ?string $unit): array
    {
        $normalizedUnit = $unit === null
            ? null
            : Str::of($unit)->squish()->lower()->trim('.')->toString();

        if ($normalizedUnit === '') {
            $normalizedUnit = null;
        }

        $definition = match ($normalizedUnit) {
            'kg', 'kgs', 'kilogram', 'kilograms' => ['g', 1000],
            'g', 'gm', 'gms', 'gram', 'grams' => ['g', 1],
            'l', 'litre', 'litres', 'liter', 'liters' => ['ml', 1000],
            'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters' => ['ml', 1],
            'tsp', 'teaspoon', 'teaspoons' => ['tsp', 1],
            'tbsp', 'tablespoon', 'tablespoons' => ['tbsp', 1],
            'cup', 'cups' => ['cup', 1],
            'clove', 'cloves', 'can', 'cans', 'tin', 'tins', 'bunch', 'bunches' => ['each', 1],
            'piece', 'pieces', 'whole', 'each', 'item', 'items' => ['each', 1],
            null => [null, 1],
            default => [Str::singular($normalizedUnit), 1],
        };

        return [
            $quantity === null ? null : $quantity * $definition[1],
            $definition[0],
        ];
    }

    private function normalizedForm(?string $preparation): ?string
    {
        if ($preparation === null || trim($preparation) === '') {
            return null;
        }

        $preparation = Str::of($preparation)->squish()->lower()->toString();
        $materialForms = collect([
            'fresh',
            'frozen',
            'dried',
            'canned',
            'tinned',
            'boneless',
            'skinless',
            'wholemeal',
            'gluten-free',
            'spiral',
            'spaghetti',
            'penne',
        ])->filter(fn (string $form): bool => Str::contains($preparation, $form));

        return $materialForms->isEmpty()
            ? null
            : $materialForms->sort()->join(' ');
    }
}
