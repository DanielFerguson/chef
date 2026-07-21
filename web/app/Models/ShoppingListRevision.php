<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\ShoppingListRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed> $snapshot */
#[Fillable(['team_id', 'shopping_list_id', 'user_id', 'revision', 'summary', 'snapshot'])]
class ShoppingListRevision extends Model
{
    /** @use HasFactory<ShoppingListRevisionFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AutomationRun, $this> */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
