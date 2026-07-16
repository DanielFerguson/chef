<?php

namespace App\Policies;

use App\Models\ConversationFeedback;
use App\Models\User;

class ConversationFeedbackPolicy
{
    public function view(User $user, ConversationFeedback $feedback): bool
    {
        return $feedback->user_id === $user->id
            && $user->memberships()->where('team_id', $feedback->team_id)->exists();
    }

    public function update(User $user, ConversationFeedback $feedback): bool
    {
        return $this->view($user, $feedback);
    }

    public function delete(User $user, ConversationFeedback $feedback): bool
    {
        return $this->view($user, $feedback);
    }
}
