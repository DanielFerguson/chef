<?php

namespace App\Actions\Shopping;

use Illuminate\Support\Str;

class ShoppingRequirementQuantity
{
    /** @return array{unit: string|null, quantity: float|null, dimension: string|null} */
    public function normalise(?string $unit, ?float $quantity): array
    {
        if ($unit === null || trim($unit) === '') {
            return $quantity === null
                ? ['unit' => null, 'quantity' => null, 'dimension' => null]
                : ['unit' => 'each', 'quantity' => round($quantity, 3), 'dimension' => 'count'];
        }

        $normalised = Str::of($unit)->squish()->lower()->toString();

        return match ($normalised) {
            'gram', 'grams', 'g' => $this->converted('g', $quantity, 'mass', 1),
            'kilogram', 'kilograms', 'kg' => $this->converted('g', $quantity, 'mass', 1000),
            'millilitre', 'millilitres', 'milliliter', 'milliliters', 'ml' => $this->converted('ml', $quantity, 'volume', 1),
            'litre', 'litres', 'liter', 'liters', 'l' => $this->converted('ml', $quantity, 'volume', 1000),
            'teaspoon', 'teaspoons', 'tsp' => $this->converted('ml', $quantity, 'volume', 5),
            'tablespoon', 'tablespoons', 'tbsp' => $this->converted('ml', $quantity, 'volume', 20),
            'cup', 'cups' => $this->converted('ml', $quantity, 'volume', 250),
            'piece', 'pieces', 'each', 'whole' => $this->converted('each', $quantity, 'count', 1),
            default => $this->converted($normalised, $quantity, 'unit:'.$normalised, 1),
        };
    }

    /**
     * @param  array<int, array{quantity: float|null, unit: string|null, dimension: string|null}>  $sources
     * @return array{unit: string|null, quantity: float|null}
     */
    public function combine(array $sources): array
    {
        $dimensions = collect($sources)->pluck('dimension')->unique()->values();

        if ($dimensions->count() !== 1) {
            return ['unit' => null, 'quantity' => null];
        }

        $units = collect($sources)->pluck('unit')->unique()->values();

        if ($units->count() !== 1 || collect($sources)->contains(fn (array $source): bool => $source['quantity'] === null)) {
            return [
                'unit' => $units->count() === 1 ? $units->first() : null,
                'quantity' => null,
            ];
        }

        return [
            'unit' => $units->first(),
            'quantity' => round((float) collect($sources)->sum('quantity'), 3),
        ];
    }

    /** @return array{unit: string, quantity: float|null, dimension: string} */
    private function converted(string $unit, ?float $quantity, string $dimension, float $multiplier): array
    {
        return [
            'unit' => $unit,
            'quantity' => $quantity === null ? null : round($quantity * $multiplier, 3),
            'dimension' => $dimension,
        ];
    }
}
