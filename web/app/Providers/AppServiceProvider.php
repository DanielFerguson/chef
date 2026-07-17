<?php

namespace App\Providers;

use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Contracts\RecipeDrafter;
use App\Ai\LaravelAiConversationEngine;
use App\Ai\LaravelAiRecipeDrafter;
use App\Ai\Testing\DeterministicRecipeDrafter;
use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\ResponsesComputerUseEngine;
use App\Automation\Testing\FakeComputerUseEngine;
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
            RecipeDrafter::class,
            $this->app->environment('testing')
                ? DeterministicRecipeDrafter::class
                : LaravelAiRecipeDrafter::class,
        );
        if ($this->app->environment('testing')) {
            $this->app->singleton(FakeComputerUseEngine::class);
            $this->app->alias(FakeComputerUseEngine::class, ComputerUseEngine::class);
        } else {
            $this->app->singleton(ComputerUseEngine::class, ResponsesComputerUseEngine::class);
        }
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
