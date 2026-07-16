<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ShoppingListItemCategory: string
{
    case FruitAndVeg = 'fruit_veg';
    case MeatAndSeafood = 'meat_seafood';
    case DairyAndEggs = 'dairy_eggs';
    case Bakery = 'bakery';
    case Pantry = 'pantry';
    case Frozen = 'frozen';
    case Drinks = 'drinks';
    case Household = 'household';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FruitAndVeg => 'Fruit & Veg',
            self::MeatAndSeafood => 'Meat & Seafood',
            self::DairyAndEggs => 'Dairy & Eggs',
            self::Bakery => 'Bakery',
            self::Pantry => 'Pantry',
            self::Frozen => 'Frozen',
            self::Drinks => 'Drinks',
            self::Household => 'Household',
            self::Other => 'Other',
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::FruitAndVeg => 10,
            self::MeatAndSeafood => 20,
            self::DairyAndEggs => 30,
            self::Bakery => 40,
            self::Pantry => 50,
            self::Frozen => 60,
            self::Drinks => 70,
            self::Household => 80,
            self::Other => 90,
        };
    }

    public static function classify(string $name): self
    {
        $name = Str::of($name)->squish()->lower()->toString();

        if (self::containsAny($name, [
            'hand soap', 'dish soap', 'dishwashing', 'dish daddy', 'scrub daddy',
            'sponge', 'cleaner', 'laundry', 'detergent', 'toilet paper', 'paper towel',
            'tissues', 'bin bag', 'garbage bag', 'foil', 'baking paper', 'cling wrap',
        ])) {
            return self::Household;
        }

        if (self::containsAny($name, [
            'sodastream', 'pepsi mix', 'iced tea', 'hot chocolate', 'coffee', 'tea bags',
            'soft drink', 'soda water', 'juice', 'cordial', 'sports drink', 'energy drink',
        ])) {
            return self::Drinks;
        }

        if (self::containsAny($name, ['frozen ', 'ice cream', 'frozen'])) {
            return self::Frozen;
        }

        if (self::containsAny($name, [
            'breadcrumb', 'panko', 'coconut milk', 'coconut cream', 'evaporated milk',
            'condensed milk', 'chicken stock', 'beef stock', 'vegetable stock', 'stock cube',
            'peanut butter', 'almond butter', 'coleslaw dressing', 'gravy', 'roux',
        ])) {
            return self::Pantry;
        }

        if (self::containsAny($name, [
            'bread', 'toast loaf', 'white loaf', 'wholemeal loaf', 'burger bun', 'hot dog bun',
            'bread roll', 'naan', 'pita', 'wraps', 'tortilla', 'croissant', 'bagel', 'english muffin',
        ])) {
            return self::Bakery;
        }

        if (self::containsAny($name, [
            'milk', 'cheese', 'yoghurt', 'yogurt', 'butter', 'cream', 'egg', 'parmesan',
            'mozzarella', 'cheddar', 'custard', 'sour cream',
        ])) {
            return self::DairyAndEggs;
        }

        if (self::containsAny($name, [
            'chicken', 'beef', 'pork', 'lamb', 'steak', 'mince', 'bacon', 'ham', 'sausage',
            'salmon', 'tuna', 'fish', 'prawn', 'shrimp', 'barramundi', 'turkey', 'duck',
        ])) {
            return self::MeatAndSeafood;
        }

        if (self::containsAny($name, [
            'potato', 'broccoli', 'carrot', 'coleslaw mix', 'cabbage', 'lettuce', 'spinach',
            'onion', 'garlic', 'ginger', 'beans', 'capsicum', 'pepper', 'tomato', 'cucumber',
            'pumpkin', 'avocado', 'lemon', 'lime', 'apple', 'banana', 'orange', 'berries',
            'mushroom', 'zucchini', 'courgette', 'celery', 'corn', 'herb', 'coriander',
            'parsley', 'basil', 'spring onion', 'shallot', 'vegetable', 'fruit', 'salad',
        ])) {
            return self::FruitAndVeg;
        }

        if (self::containsAny($name, [
            'rice', 'pasta', 'noodle', 'couscous', 'flour', 'sugar', 'salt', 'spice', 'seasoning',
            'paprika', 'cumin', 'turmeric', 'curry', 'sauce', 'soy', 'vinegar', 'oil', 'honey',
            'mayonnaise', 'mustard', 'ketchup', 'beans', 'lentil', 'chickpea', 'tinned', 'canned',
            'jam', 'cereal', 'oats', 'biscuit', 'cracker', 'chocolate',
        ])) {
            return self::Pantry;
        }

        return self::Other;
    }

    /** @param array<int, string> $needles */
    private static function containsAny(string $name, array $needles): bool
    {
        return Str::contains($name, $needles, ignoreCase: true);
    }
}
