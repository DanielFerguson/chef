<?php

namespace App\Providers;

use App\Ai\Contracts\CartProductCandidateSelector;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\LaravelAiCartProductCandidateSelector;
use App\Ai\LaravelAiConversationEngine;
use App\Ai\LaravelAiMealPlanRecipeDrafter;
use App\Ai\LaravelAiShoppingListDrafter;
use App\Ai\Testing\DeterministicCartProductCandidateSelector;
use App\Ai\Testing\DeterministicMealPlanRecipeDrafter;
use App\Ai\Testing\DisabledShoppingListDrafter;
use App\Automation\Browserbase\BrowserbaseBrowserSessionProvider;
use App\Automation\Browserbase\TypeScriptComputerExecutor;
use App\Automation\Browserbase\WoolworthsCartAdapter;
use App\Automation\Browserbase\WoolworthsCatalogueDiscovery;
use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Contracts\RetailerProductDiscovery;
use App\Automation\Testing\FakeBrowserSessionProvider;
use App\Automation\Testing\FakeComputerExecutor;
use App\Automation\Testing\FakeRetailerProductDiscovery;
use App\Retailer\Browserbase\StagehandRetailerBrowser;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Testing\FakeRetailerBrowser;
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
            ShoppingListDrafter::class,
            $this->app->environment('testing')
                ? DisabledShoppingListDrafter::class
                : LaravelAiShoppingListDrafter::class,
        );
        $this->app->bind(
            CartProductCandidateSelector::class,
            $this->app->environment('testing')
                ? DeterministicCartProductCandidateSelector::class
                : LaravelAiCartProductCandidateSelector::class,
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
        $this->app->bind(RetailerCartAdapter::class, WoolworthsCartAdapter::class);
        $this->app->singleton(
            RetailerProductDiscovery::class,
            $this->app->environment('testing')
                ? FakeRetailerProductDiscovery::class
                : WoolworthsCatalogueDiscovery::class,
        );

        $this->app->singleton(
            RetailerBrowser::class,
            $this->app->environment('testing')
                ? FakeRetailerBrowser::class
                : StagehandRetailerBrowser::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // queue:listen's child Process defaults to a 60s kill and aborts long
            // AI jobs (e.g. MaterializeMealPlanRecipesJob at 180s). Use queue:work.
            DevCommands::artisan(
                'queue:work --queue=default,ai,automation --tries=1 --timeout=0',
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
