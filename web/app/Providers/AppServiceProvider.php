<?php

namespace App\Providers;

use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\LaravelAiConversationEngine;
use App\Ai\LaravelAiMealPlanRecipeDrafter;
use App\Ai\LaravelAiShoppingListDrafter;
use App\Ai\Testing\DeterministicMealPlanRecipeDrafter;
use App\Ai\Testing\DisabledShoppingListDrafter;
use App\Automation\Browserbase\BrowserbaseBrowserSessionProvider;
use App\Automation\Browserbase\TypeScriptComputerExecutor;
use App\Automation\Browserbase\WoolworthsCartAdapter;
use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\ComputerUseClient;
use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\LaravelComputerUseEngine;
use App\Automation\OpenAI\OpenAIComputerUseClient;
use App\Automation\Testing\FakeBrowserSessionProvider;
use App\Automation\Testing\FakeComputerExecutor;
use App\Automation\Testing\FakeComputerUseClient;
use Carbon\CarbonImmutable;
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
            ShoppingListDrafter::class,
            $this->app->environment('testing')
                ? DisabledShoppingListDrafter::class
                : LaravelAiShoppingListDrafter::class,
        );
        $this->app->singleton(
            BrowserSessionProvider::class,
            $this->app->environment('testing')
                ? FakeBrowserSessionProvider::class
                : BrowserbaseBrowserSessionProvider::class,
        );
        $this->app->singleton(
            ComputerExecutor::class,
            $this->app->environment('testing')
                ? FakeComputerExecutor::class
                : TypeScriptComputerExecutor::class,
        );
        $this->app->singleton(
            ComputerUseClient::class,
            $this->app->environment('testing')
                ? FakeComputerUseClient::class
                : OpenAIComputerUseClient::class,
        );
        $this->app->bind(RetailerCartAdapter::class, WoolworthsCartAdapter::class);
        $this->app->bind(ComputerUseEngine::class, LaravelComputerUseEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
