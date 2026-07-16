<?php

namespace App\Enums;

enum ConversationFeedbackContext: string
{
    case AssistantMessage = 'assistant_message';
    case PlanningConfirmed = 'planning_confirmed';
}
