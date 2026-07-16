<?php

use App\Enums\ShoppingListItemCategory;

it('classifies common grocery and household items into stable shopping sections', function (string $name, ShoppingListItemCategory $category) {
    expect(ShoppingListItemCategory::classify($name))->toBe($category);
})->with([
    ['Baby potatoes', ShoppingListItemCategory::FruitAndVeg],
    ['Chicken breast', ShoppingListItemCategory::MeatAndSeafood],
    ['Coconut milk', ShoppingListItemCategory::Pantry],
    ['Eggs', ShoppingListItemCategory::DairyAndEggs],
    ['Toast white bread', ShoppingListItemCategory::Bakery],
    ['Frozen mixed vegetables', ShoppingListItemCategory::Frozen],
    ['SodaStream Pepsi mix', ShoppingListItemCategory::Drinks],
    ['Dish Daddy sponge', ShoppingListItemCategory::Household],
    ['Unfamiliar speciality item', ShoppingListItemCategory::Other],
]);

it('exposes categories in shopping order', function () {
    expect(collect(ShoppingListItemCategory::cases())->sortBy->position()->pluck('value')->all())
        ->toBe([
            'fruit_veg',
            'meat_seafood',
            'dairy_eggs',
            'bakery',
            'pantry',
            'frozen',
            'drinks',
            'household',
            'other',
        ]);
});
