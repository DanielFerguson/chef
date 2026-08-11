<?php

namespace App\Ai\Tools;

use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ResolveMealSlotParticipants;
use App\Enums\MealSlotKind;
use App\Models\MealPlan;
use App\Models\MealSlot;
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
        private readonly ResolveMealSlotParticipants $resolveParticipants,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a dated breakfast, lunch, dinner, snack, or custom occasion to the current plan with its participating people.';
    }

    public function handle(Request $request): Stringable|string
    {
        $date = CarbonImmutable::parse($request->string('date')->toString());
        $kind = MealSlotKind::from($request->string('kind')->toString());
        $explicitServings = null;

        if ($request->exists('participants')) {
            $explicitServings = collect($request->array('participants'))
                ->mapWithKeys(fn (array $participant): array => [
                    (int) $participant['person_id'] => (float) $participant['servings'],
                ])
                ->all();
        } elseif ($request->exists('participant_ids')) {
            $explicitServings = collect($request->array('participant_ids'))
                ->mapWithKeys(fn (int $personId): array => [$personId => 1.0])
                ->all();
        }

        $assignment = $this->resolveParticipants->handle(
            $this->mealPlan,
            $date,
            $kind,
            $explicitServings,
        );
        $participants = Person::query()
            ->where('team_id', $this->mealPlan->team_id)
            ->whereIn('id', array_keys($assignment['servings_by_person']))
            ->get();
        $sourceSlot = $assignment['source_meal_slot_id'] === null
            ? null
            : MealSlot::query()
                ->where('team_id', $this->mealPlan->team_id)
                ->findOrFail($assignment['source_meal_slot_id']);

        $slot = $this->createMealSlot->handle(
            mealPlan: $this->mealPlan,
            user: $this->actor,
            date: $date,
            kind: $kind,
            participants: $participants,
            label: $request->string('label')->toString() ?: null,
            sourceMessage: $this->sourceMessage,
            servingsByPerson: $assignment['servings_by_person'],
            participantOrigin: $assignment['origin'],
            participantSourceSlot: $sourceSlot,
        );

        return $slot->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Date in YYYY-MM-DD format.')->required(),
            'kind' => $schema->string()->description('breakfast, lunch, dinner, snack, or custom.')->required(),
            'participants' => $schema->array()
                ->items($schema->object([
                    'person_id' => $schema->integer()->description('Identifier of a person who is eating.')->required(),
                    'servings' => $schema->number()->description('Positive servings for this person.')->required(),
                ])->withoutAdditionalProperties())
                ->description('Optional explicit people and servings. Omit this to let Chef apply a visible provisional household default.'),
            'participant_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Legacy optional list of people eating, each with one serving.'),
            'label' => $schema->string()->description('Required only for a custom occasion.'),
        ];
    }
}
