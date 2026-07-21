<?php

namespace App\Ai;

use App\Ai\Data\ConversationFailure;
use App\Enums\ConversationFailureCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use LogicException;
use RuntimeException;
use Throwable;

class ClassifyConversationFailure
{
    public function handle(Throwable $exception): ConversationFailure
    {
        return match (true) {
            $exception instanceof RateLimitedException => new ConversationFailure(
                ConversationFailureCode::RateLimited,
                'Chef is receiving too many requests right now. Retry this message.',
            ),
            $exception instanceof ProviderOverloadedException => new ConversationFailure(
                ConversationFailureCode::ProviderOverloaded,
                'Chef is temporarily overloaded. Retry this message.',
            ),
            $exception instanceof InsufficientCreditsException => new ConversationFailure(
                ConversationFailureCode::InsufficientCredits,
                'Chef is unavailable until its AI service is reconfigured. Retry after that is fixed.',
            ),
            $exception instanceof InvalidArgumentException,
            $exception instanceof LogicException => new ConversationFailure(
                ConversationFailureCode::ConfigurationError,
                'Chef is unavailable until its AI service configuration is fixed. Retry after that is fixed.',
            ),
            $exception instanceof NoSuchToolException => new ConversationFailure(
                ConversationFailureCode::ToolError,
                'Chef could not apply that planning change. Retry this message.',
            ),
            $exception instanceof ValidationException,
            $exception instanceof ModelNotFoundException => new ConversationFailure(
                ConversationFailureCode::ToolError,
                'Chef could not apply that planning change. Retry this message.',
            ),
            $this->isEmptyResponse($exception) => new ConversationFailure(
                ConversationFailureCode::EmptyResponse,
                'Chef finished without a response. Retry this message.',
            ),
            $exception instanceof AiException => new ConversationFailure(
                ConversationFailureCode::ProviderError,
                'Chef hit a temporary service problem. Retry this message.',
            ),
            default => new ConversationFailure(
                ConversationFailureCode::Unknown,
                'Chef could not finish that response. Retry this message.',
            ),
        };
    }

    private function isEmptyResponse(Throwable $exception): bool
    {
        return $exception instanceof RuntimeException
            && str_starts_with($exception->getMessage(), 'Chef completed without');
    }
}
