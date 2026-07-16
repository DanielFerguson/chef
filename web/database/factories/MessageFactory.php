<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes) => Conversation::query()->findOrFail((int) $attributes['conversation_id'])->team_id,
            'conversation_id' => Conversation::factory(),
            'user_id' => null,
            'role' => MessageRole::Assistant,
            'content' => fake()->sentence(),
        ];
    }
}
