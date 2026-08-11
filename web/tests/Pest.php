<?php

use App\Ai\Agents\ChefAgent;
use App\Ai\Agents\MealPlanAdjustmentDraftingAgent;
use App\Ai\Agents\MealPlanRecipeDraftingAgent;
use App\Ai\Agents\RetailerProductRankingAgent;
use App\Ai\Agents\RetailerSearchRecoveryAgent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

$applicationRoot = dirname(__DIR__);

if (getcwd() !== $applicationRoot && ! chdir($applicationRoot)) {
    throw new RuntimeException("Unable to enter Chef's Laravel application root [{$applicationRoot}].");
}

pest()->extend(TestCase::class)
    ->in('Browser', 'Evals', 'Feature', 'Integration', 'Unit');

pest()->use(RefreshDatabase::class)
    ->in('Browser', 'Evals', 'Feature', 'Unit');

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'Australia/Melbourne'));

    $testFile = (string) (new ReflectionClass($this))->getStaticPropertyValue('__filename');

    if (str_contains($testFile, DIRECTORY_SEPARATOR.'Evals'.DIRECTORY_SEPARATOR)) {
        return;
    }

    Http::preventStrayRequests();

    foreach ([
        ChefAgent::class,
        MealPlanAdjustmentDraftingAgent::class,
        MealPlanRecipeDraftingAgent::class,
        RetailerProductRankingAgent::class,
        RetailerSearchRecoveryAgent::class,
    ] as $agent) {
        $agent::fake()->preventStrayPrompts();
    }
});

afterEach(function (): void {
    Date::setTestNow();
});

if (getenv('CHEF_PEST_TIA_BOOTSTRAPPED') === '1') {
    pest()->tia()
        ->always()
        ->locally();
}
