<?php

namespace App\Actions\Shopping;

use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddShoppingListItem
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    public function handle(ShoppingList $shoppingList, User $user, string $name, ?float $quantity, ?string $unit, ?string $note, bool $staple, int $expectedRevision): ShoppingListItem
    {
        if (! $user->can('update', $shoppingList)) {
            throw new AuthorizationException('You cannot update this shopping list.');
        }

        return DB::transaction(function () use ($shoppingList, $user, $name, $quantity, $unit, $note, $staple, $expectedRevision): ShoppingListItem {
            $shoppingList = $this->ensureEditable->handle($shoppingList);
            $position = ($shoppingList->items()->max('position') ?? 0) + 1;
            $item = $shoppingList->items()->create([
                'team_id' => $shoppingList->team_id,
                'created_by_user_id' => $user->id,
                'source_kind' => $staple ? ShoppingListItemSourceKind::Staple : ShoppingListItemSourceKind::Manual,
                'name' => Str::squish($name),
                'normalized_name' => Str::of($name)->squish()->lower()->toString(),
                'quantity' => $quantity,
                'unit' => $unit === null ? null : Str::of($unit)->squish()->lower()->toString(),
                'note' => $note,
                'included' => true,
                'position' => $position,
            ]);
            $shoppingList->update(['status' => ShoppingListStatus::Draft, 'completed_at' => null]);
            $this->recordRevision->handle($shoppingList, $user, 'Added '.$item->name, $expectedRevision);

            return $item;
        });
    }
}
