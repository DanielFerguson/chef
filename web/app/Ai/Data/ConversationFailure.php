<?php

namespace App\Ai\Data;

use App\Enums\ConversationFailureCode;

readonly class ConversationFailure
{
    public function __construct(
        public ConversationFailureCode $code,
        public string $message,
        public bool $retryable = true,
    ) {}
}
