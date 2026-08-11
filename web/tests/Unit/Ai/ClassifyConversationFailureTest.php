<?php

use App\Ai\ClassifyConversationFailure;
use App\Enums\ConversationFailureCode;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

it('classifies conversation failures without exposing their raw messages', function (Closure $exceptionFactory, ConversationFailureCode $code) {
    $exception = $exceptionFactory();
    $failure = (new ClassifyConversationFailure)->handle($exception);

    expect($failure->code)->toBe($code)
        ->and($failure->retryable)->toBeTrue()
        ->and($failure->message)->not->toContain($exception->getMessage());
})->with([
    'rate limited' => [fn () => RateLimitedException::forProvider('openai', 429), ConversationFailureCode::RateLimited],
    'provider overloaded' => [fn () => ProviderOverloadedException::forProvider('openai', 503), ConversationFailureCode::ProviderOverloaded],
    'insufficient credits' => [fn () => InsufficientCreditsException::forProvider('openai', 402), ConversationFailureCode::InsufficientCredits],
    'configuration error' => [fn () => new InvalidArgumentException('Missing provider setting'), ConversationFailureCode::ConfigurationError],
    'missing tool' => [fn () => new NoSuchToolException('UntrustedToolName'), ConversationFailureCode::ToolError],
    'invalid tool arguments' => [fn () => ValidationException::withMessages(['field' => 'Invalid tool input']), ConversationFailureCode::ToolError],
    'missing tool resource' => [fn () => (new ModelNotFoundException)->setModel(User::class, [42]), ConversationFailureCode::ToolError],
    'empty response' => [fn () => new RuntimeException('Chef completed without a visible response.'), ConversationFailureCode::EmptyResponse],
    'provider error' => [fn () => new AiException('Provider response body'), ConversationFailureCode::ProviderError],
    'unknown' => [fn () => new RuntimeException('Unexpected household content'), ConversationFailureCode::Unknown],
]);
