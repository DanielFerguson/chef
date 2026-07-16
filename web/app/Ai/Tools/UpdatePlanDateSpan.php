<?php

namespace App\Ai\Tools;

use App\Actions\MealPlans\UpdateMealPlanDateSpan;
use App\Models\MealPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdatePlanDateSpan implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly UpdateMealPlanDateSpan $updateDateSpan,
    ) {}

    public function description(): Stringable|string
    {
        return 'Change the current plan start and end dates when the user states the desired date span.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->updateDateSpan->handle(
            $this->mealPlan,
            $this->actor,
            CarbonImmutable::parse($request->string('starts_on')->toString()),
            CarbonImmutable::parse($request->string('ends_on')->toString()),
        )->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'starts_on' => $schema->string()->description('Start date in YYYY-MM-DD format.')->required(),
            'ends_on' => $schema->string()->description('End date in YYYY-MM-DD format.')->required(),
        ];
    }
}
