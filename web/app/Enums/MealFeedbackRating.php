<?php

namespace App\Enums;

enum MealFeedbackRating: string
{
    case Dislike = 'dislike';
    case Neutral = 'neutral';
    case Like = 'like';
    case Favourite = 'favourite';
}
