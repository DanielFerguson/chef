<?php

namespace App\Enums;

enum ConversationFeedbackReason: string
{
    case Misunderstood = 'misunderstood';
    case WrongAction = 'wrong_action';
    case PlanNotUpdated = 'plan_not_updated';
    case IncorrectHouseholdInformation = 'incorrect_household_information';
    case PoorRecommendation = 'poor_recommendation';
    case NoForwardMomentum = 'no_forward_momentum';
    case ResponseFailure = 'response_failure';
}
