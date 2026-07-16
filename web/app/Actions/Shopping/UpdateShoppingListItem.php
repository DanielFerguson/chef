<?php

namespace App\Actions\Shopping;

use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListStatus;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateShoppingListItem
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    /** @param array<string, mixed> $changes */
    public function handle(ShoppingListItem $item, User $user, array $changes, ?int $expectedRevision = null): ShoppingListItem
    {
        if (! $user->can('update', $item)) {
            throw new AuthorizationException('You cannot update this shopping-list item.');
        }

        $mergeSafeCheckOff = array_keys($changes) === ['checked'];

        if (! $mergeSafeCheckOff && $expectedRevision === null) {
            throw ValidationException::withMessages([
                'expected_revision' => 'The current shopping-list revision is required for this change.',
            ]);
        }

        return DB::transaction(function () use ($item, $user, $changes, $expectedRevision): ShoppingListItem {
            $shoppingList = $this->ensureEditable->handle($item->shoppingList);

            if (isset($changes['name'])) {
                $changes['name'] = Str::squish($changes['name']);
                $changes['normalized_name'] = Str::of($changes['name'])->lower()->toString();

                if (! array_key_exists('category', $changes)) {
                    $changes['category'] = ShoppingListItemCategory::classify($changes['name']);
                }
            }

            if (isset($changes['category'])) {
                $category = $changes['category'] instanceof ShoppingListItemCategory
                    ? $changes['category']
                    : ShoppingListItemCategory::tryFrom((string) $changes['category']);

                if ($category === null) {
                    throw ValidationException::withMessages([
                        'category' => 'Choose a valid shopping category.',
                    ]);
                }

                $changes['category'] = $category;
            }

            if (array_key_exists('unit', $changes)) {
                $changes['unit'] = $changes['unit'] === null ? null : Str::of($changes['unit'])->squish()->lower()->toString();
            }

            $item->update($changes);
            $shoppingList->update(['status' => ShoppingListStatus::Draft, 'completed_at' => null]);
            $this->recordRevision->handle($shoppingList, $user, 'Updated '.$item->name, $expectedRevision);

            return $item->refresh();
        });
    }
}
