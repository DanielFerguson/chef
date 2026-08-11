<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes) => Message::query()
                ->findOrFail((int) $attributes['message_id'])
                ->team_id,
            'message_id' => Message::factory(),
            'disk' => 'local',
            'path' => 'conversation-images/'.fake()->uuid().'.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => fake()->numberBetween(10_000, 500_000),
            'width' => 1200,
            'height' => 900,
            'source_sha256' => hash('sha256', fake()->uuid()),
            'position' => 0,
        ];
    }
}
