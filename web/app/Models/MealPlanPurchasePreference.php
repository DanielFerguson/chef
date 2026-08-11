<?php

namespace App\Models;

use App\Enums\RetailerProvider;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\MealPlanPurchasePreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RetailerProvider $provider
 * @property int|null $basket_target_cents
 */
#[Fillable(['team_id', 'meal_plan_id', 'updated_by_user_id', 'source_message_id', 'provider', 'basket_target_cents'])]
class MealPlanPurchasePreference extends Model
{
    /** @use HasFactory<MealPlanPurchasePreferenceFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['provider' => RetailerProvider::class];
    }
}
