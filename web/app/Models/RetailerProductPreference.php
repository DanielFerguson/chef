<?php

namespace App\Models;

use App\Enums\RetailerProvider;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\RetailerProductPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property RetailerProvider $provider
 * @property int $created_by_user_id
 * @property Carbon|null $revoked_at
 */
#[Fillable(['team_id', 'created_by_user_id', 'source_message_id', 'provider', 'normalized_name', 'normalized_form', 'sku', 'product_title', 'reason', 'revoked_at'])]
class RetailerProductPreference extends Model
{
    /** @use HasFactory<RetailerProductPreferenceFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    protected function casts(): array
    {
        return [
            'provider' => RetailerProvider::class,
            'revoked_at' => 'datetime',
        ];
    }
}
