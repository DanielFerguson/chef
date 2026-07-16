<?php

namespace App\Enums;

enum ConversationFeedbackRating: string
{
    case Helpful = 'helpful';
    case Unhelpful = 'unhelpful';
}
