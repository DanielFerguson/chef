<?php

namespace App\Actions\Shopping;

use Illuminate\Support\Str;

class ShoppingItemIdentity
{
    public function key(string $name): string
    {
        $normalised = Str::of($name)
            ->lower()
            ->replace(['&'], [' and '])
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->squish()
            ->toString();
        $singular = Str::singular($normalised);

        return match ($singular) {
            'bbq sauce' => 'barbecue sauce',
            'fresh ginger' => 'ginger',
            'fresh tomato' => 'tomato',
            'whole egg mayonnaise', 'whole egg mayo', 'mayo' => 'mayonnaise',
            'passatum' => 'passata',
            default => $singular,
        };
    }

    public function isTapWater(string $name): bool
    {
        return in_array($this->key($name), [
            'water',
            'tap water',
            'cold water',
            'warm water',
            'hot water',
            'boiling water',
            'room temperature water',
        ], true);
    }
}
