<?php

namespace App\Providers;

use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Contracts\MealPlanAdjustmentDrafter;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Contracts\RetailerProductRanker;
use App\Ai\Contracts\RetailerSearchRecovery;
use App\Ai\LaravelAiConversationEngine;
use App\Ai\LaravelAiMealPlanAdjustmentDrafter;
use App\Ai\LaravelAiMealPlanRecipeDrafter;
use App\Ai\LaravelAiRetailerProductRanker;
use App\Ai\LaravelAiRetailerSearchRecovery;
use App\Ai\Testing\DeterministicMealPlanAdjustmentDrafter;
use App\Ai\Testing\DeterministicMealPlanRecipeDrafter;
use App\Ai\Testing\DeterministicRetailerProductRanker;
use App\Ai\Testing\DeterministicRetailerSearchRecovery;
use App\Models\BasketRun;
use App\Observers\BasketRunObserver;
use App\Retailer\Browserbase\TypeScriptRetailerAutomationGateway;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Testing\FakeRetailerAutomationGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ChefConversationEngine::class, LaravelAiConversationEngine::class);
        $this->app->bind(
            MealPlanRecipeDrafter::class,
            $this->app->environment('testing')
                ? DeterministicMealPlanRecipeDrafter::class
                : LaravelAiMealPlanRecipeDrafter::class,
        );
        $this->app->bind(
            MealPlanAdjustmentDrafter::class,
            $this->app->environment('testing')
                ? DeterministicMealPlanAdjustmentDrafter::class
                : LaravelAiMealPlanAdjustmentDrafter::class,
        );
        $this->app->bind(
            RetailerProductRanker::class,
            $this->app->environment('testing')
                ? DeterministicRetailerProductRanker::class
                : LaravelAiRetailerProductRanker::class,
        );
        $this->app->bind(
            RetailerSearchRecovery::class,
            $this->app->environment('testing')
                ? DeterministicRetailerSearchRecovery::class
                : LaravelAiRetailerSearchRecovery::class,
        );
        $this->app->singleton(
            RetailerAutomationGateway::class,
            fn () => $this->app->environment('testing')
                ? new FakeRetailerAutomationGateway
                : new TypeScriptRetailerAutomationGateway,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        BasketRun::observe(BasketRunObserver::class);

        if ($this->app->runningInConsole()) {
            // Reload application code between local jobs while allowing each job
            // to define its own execution timeout.
            DevCommands::artisan(
                'queue:listen --queue=default,ai,retailer --tries=1 --timeout=0',
                'queue',
            )->purple();
        }

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
