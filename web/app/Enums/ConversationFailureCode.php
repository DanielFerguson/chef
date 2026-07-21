<?php

namespace App\Enums;

enum ConversationFailureCode: string
{
    case RateLimited = 'rate_limited';
    case ProviderOverloaded = 'provider_overloaded';
    case InsufficientCredits = 'insufficient_credits';
    case ConfigurationError = 'configuration_error';
    case ProviderError = 'provider_error';
    case ToolError = 'tool_error';
    case EmptyResponse = 'empty_response';
    case Unknown = 'unknown';
}
