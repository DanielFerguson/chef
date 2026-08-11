<?php

namespace Database\Factories;

use App\Models\PendingMessageAttachmentDeletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingMessageAttachmentDeletion>
 */
class PendingMessageAttachmentDeletionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => 'local',
            'path' => 'conversation-images/pending/'.fake()->uuid().'.jpg',
        ];
    }
}
