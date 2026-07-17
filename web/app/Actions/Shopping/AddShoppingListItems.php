<?php

namespace App\Actions\Shopping;

use App\Enums\MessageRole;
use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Models\Message;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AddShoppingListItems
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, ShoppingListItem>
     */
    public function handle(ShoppingList $shoppingList, User $user, Message $sourceMessage, array $items): Collection
    {
        if (! $user->can('update', $shoppingList)
            || $sourceMessage->team_id !== $shoppingList->team_id
            || $sourceMessage->user_id !== $user->id
            || $sourceMessage->role !== MessageRole::User) {
            throw new AuthorizationException('You cannot add these shopping-list items.');
        }

        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:80'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
            'items.*.staple' => ['required', 'boolean'],
        ])->validate();

        $requested = collect($items)
            ->map(function (array $item) use ($shoppingList, $sourceMessage): array {
                $name = Str::squish((string) $item['name']);
                $normalizedName = Str::lower($name);

                return [
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                    'quantity' => array_key_exists('quantity', $item) && $item['quantity'] !== null
                        ? (float) $item['quantity']
                        : null,
                    'unit' => filled($item['unit'] ?? null)
                        ? Str::of((string) $item['unit'])->squish()->lower()->toString()
                        : null,
                    'note' => filled($item['note'] ?? null) ? trim((string) $item['note']) : null,
                    'staple' => (bool) $item['staple'],
                    'idempotency_key' => hash('sha256', implode('|', [
                        'shopping-list-item',
                        $shoppingList->id,
                        $sourceMessage->id,
                        $normalizedName,
                    ])),
                ];
            })
            ->unique('normalized_name')
            ->values();

        /** @var Collection<int, ShoppingListItem> */
        return DB::transaction(function () use ($shoppingList, $user, $sourceMessage, $requested): Collection {
            $shoppingList = $this->ensureEditable->handle($shoppingList);
            $existingNames = $shoppingList->items()
                ->whereIn('normalized_name', $requested->pluck('normalized_name'))
                ->pluck('normalized_name')
                ->all();
            $position = (int) ($shoppingList->items()->max('position') ?? 0);
            $added = [];

            foreach ($requested as $item) {
                if (in_array($item['normalized_name'], $existingNames, true)) {
                    continue;
                }

                $position++;
                $added[] = $shoppingList->items()->create([
                    'team_id' => $shoppingList->team_id,
                    'created_by_user_id' => $user->id,
                    'source_message_id' => $sourceMessage->id,
                    'idempotency_key' => $item['idempotency_key'],
                    'source_kind' => $item['staple'] ? ShoppingListItemSourceKind::Staple : ShoppingListItemSourceKind::Manual,
                    'category' => ShoppingListItemCategory::classify($item['name']),
                    'name' => $item['name'],
                    'normalized_name' => $item['normalized_name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'note' => $item['note'],
                    'included' => true,
                    'position' => $position,
                ]);
            }

            if ($added !== []) {
                $shoppingList->update(['status' => ShoppingListStatus::Draft, 'completed_at' => null]);
                $this->recordRevision->handle(
                    $shoppingList,
                    $user,
                    $this->revisionSummary($added),
                );
            }

            $names = $requested->pluck('normalized_name')->all();

            return $shoppingList->items()
                ->whereIn('normalized_name', $names)
                ->get()
                ->sortBy(fn (ShoppingListItem $item): int => (int) array_search($item->normalized_name, $names, true))
                ->unique('normalized_name')
                ->values();
        });
    }

    /** @param array<int, ShoppingListItem> $items */
    private function revisionSummary(array $items): string
    {
        $names = collect($items)->pluck('name')->values();

        return match ($names->count()) {
            1 => 'Added '.$names->first(),
            2 => 'Added '.$names->first().' and '.$names->last(),
            default => 'Added '.$names->count().' shopping items',
        };
    }
}
