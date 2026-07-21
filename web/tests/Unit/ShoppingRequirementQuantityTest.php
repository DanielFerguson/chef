<?php

use App\Actions\Shopping\ShoppingItemIdentity;
use App\Actions\Shopping\ShoppingRequirementQuantity;

it('normalises Australian shopping dimensions and count synonyms', function () {
    $quantities = new ShoppingRequirementQuantity;

    expect($quantities->normalise('kg', 1.5))->toBe(['unit' => 'g', 'quantity' => 1500.0, 'dimension' => 'mass'])
        ->and($quantities->normalise('litres', 2))->toBe(['unit' => 'ml', 'quantity' => 2000.0, 'dimension' => 'volume'])
        ->and($quantities->normalise('cup', 1))->toBe(['unit' => 'ml', 'quantity' => 250.0, 'dimension' => 'volume'])
        ->and($quantities->normalise('tablespoon', 1))->toBe(['unit' => 'ml', 'quantity' => 20.0, 'dimension' => 'volume'])
        ->and($quantities->normalise('teaspoon', 1))->toBe(['unit' => 'ml', 'quantity' => 5.0, 'dimension' => 'volume'])
        ->and($quantities->normalise('pieces', 2))->toBe(['unit' => 'each', 'quantity' => 2.0, 'dimension' => 'count']);
});

it('sums compatible sources and retains null totals for unknown or incompatible quantities', function () {
    $quantities = new ShoppingRequirementQuantity;

    expect($quantities->combine([
        ['unit' => 'g', 'quantity' => 500.0, 'dimension' => 'mass'],
        ['unit' => 'g', 'quantity' => 250.0, 'dimension' => 'mass'],
    ]))->toBe(['unit' => 'g', 'quantity' => 750.0])
        ->and($quantities->combine([
            ['unit' => 'g', 'quantity' => 500.0, 'dimension' => 'mass'],
            ['unit' => 'ml', 'quantity' => 250.0, 'dimension' => 'volume'],
        ]))->toBe(['unit' => null, 'quantity' => null])
        ->and($quantities->combine([
            ['unit' => 'bunch', 'quantity' => 1.0, 'dimension' => 'unit:bunch'],
            ['unit' => 'bunch', 'quantity' => null, 'dimension' => 'unit:bunch'],
        ]))->toBe(['unit' => 'bunch', 'quantity' => null]);
});

it('canonicalises reviewed aliases while preserving material ingredient distinctions', function () {
    $identity = new ShoppingItemIdentity;

    expect($identity->key('BBQ sauce'))->toBe('barbecue sauce')
        ->and($identity->key('Fresh ginger'))->toBe('ginger')
        ->and($identity->key('Whole-egg mayonnaise'))->toBe('mayonnaise')
        ->and($identity->key('Fresh tomatoes'))->toBe('tomato')
        ->and($identity->key('Basmati rice'))->not->toBe($identity->key('Jasmine rice'))
        ->and($identity->key('Olive oil'))->not->toBe($identity->key('Vegetable oil'))
        ->and($identity->key('Tomato'))->not->toBe($identity->key('Passata'))
        ->and($identity->key('Tomato'))->not->toBe($identity->key('Tomato paste'))
        ->and($identity->key('Fresh ginger'))->not->toBe($identity->key('Ground ginger'));
});
