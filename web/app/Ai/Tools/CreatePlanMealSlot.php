<?php

namespace App\Ai\Tools;

use App\Actions\Planning\CreateMealSlot;
use App\Enums\MealSlotKind;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreatePlanMealSlot implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly CreateMealSlot $createMealSlot,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a dated breakfast, lunch, dinner, snack, or custom occasion to the current plan with its participating people.';
    }

    public function handle(Request $request): Stringable|string
    {
        $participants = Person::query()
            ->where('team_id', $this->mealPlan->team_id)
            ->whereIn('id', $request->array('participant_ids'))
            ->get();

        $slot = $this->createMealSlot->handle(
            mealPlan: $this->mealPlan,
            user: $this->actor,
            date: CarbonImmutable::parse($request->string('date')->toString()),
            kind: MealSlotKind::from($request->string('kind')->toString()),
            participants: $participants,
            label: $request->string('label')->toString() ?: null,
            sourceMessage: $this->sourceMessage,
        );

        return $slot->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Date in YYYY-MM-DD format.')->required(),
            'kind' => $schema->string()->description('breakfast, lunch, dinner, snack, or custom.')->required(),
            'participant_ids' => $schema->array()->items($schema->integer())->description('Identifiers of everyone eating.')->required(),
            'label' => $schema->string()->description('Required only for a custom occasion.'),
        ];
    }
}
