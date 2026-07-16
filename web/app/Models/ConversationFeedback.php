<?php

namespace App\Models;

use App\Enums\ConversationFeedbackContext;
use App\Enums\ConversationFeedbackRating;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $conversation_id
 * @property int|null $message_id
 * @property int|null $meal_plan_id
 * @property int $user_id
 * @property ConversationFeedbackContext $context
 * @property ConversationFeedbackRating $rating
 * @property array<int, string>|null $reasons
 * @property string|null $comment
 */
#[Fillable(['team_id', 'conversation_id', 'message_id', 'meal_plan_id', 'user_id', 'context', 'rating', 'reasons', 'comment', 'plan_revision', 'milestone', 'invocation_id'])]
class ConversationFeedback extends Model
{
    use ResolvesWithinCurrentTeam;

    protected $table = 'conversation_feedback';

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => ConversationFeedbackContext::class,
            'rating' => ConversationFeedbackRating::class,
            'reasons' => 'array',
        ];
    }
}
