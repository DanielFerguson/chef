<?php

namespace App\Ai\Contracts;

use App\Ai\Data\AssistantReply;
use App\Ai\Data\AssistantStreamChunk;
use App\Models\Conversation;
use App\Models\Message;

interface ChefConversationEngine
{
    public function respondTo(Conversation $conversation, Message $message): AssistantReply;

    /** @return iterable<AssistantStreamChunk> */
    public function streamResponse(Conversation $conversation, Message $message): iterable;
}
