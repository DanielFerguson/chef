<?php

use App\Actions\Automation\HeartbeatBrowserActors;
use App\Actions\Shopping\GenerateShoppingList;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('chef:automation-actors:heartbeat', function (HeartbeatBrowserActors $heartbeat): int {
    $counts = $heartbeat->handle();
    $this->line(sprintf(
        'Browser actors: %d checked, %d healthy, %d recovered, %d lost.',
        $counts['checked'],
        $counts['healthy'],
        $counts['recovered'],
        $counts['lost'],
    ));

    return $counts['lost'] === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Refresh and fence persistent Browserbase session actors');

Schedule::command('chef:automation-actors:heartbeat')->everyMinute()->withoutOverlapping();

Artisan::command('chef:shopping-list:regenerate {mealPlan} {--user= : User ID performing the authorised regeneration}', function (GenerateShoppingList $generate): int {
    $mealPlan = MealPlan::query()->findOrFail((int) $this->argument('mealPlan'));
    $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT);

    if ($userId === false) {
        $this->error('Provide the authorised user with --user=<id>.');

        return Command::FAILURE;
    }

    $user = User::query()->findOrFail($userId);
    try {
        $shoppingList = $generate->handle($mealPlan, $user, force: true)->load('items');
    } catch (AuthorizationException) {
        $this->error('That user is not authorised to regenerate this meal plan.');

        return Command::FAILURE;
    }

    $this->info(sprintf(
        'Shopping list %d revision %d regenerated with %d items.',
        $shoppingList->id,
        $shoppingList->revision,
        $shoppingList->items->count(),
    ));
    $this->line('Generation path: '.$shoppingList->last_generation_method->value);

    return Command::SUCCESS;
})->purpose('Force-regenerate a meal plan shopping list as an authorised user');

Artisan::command('chef:automation:status', function (): int {
    $checks = [
        'Browserbase API key' => filled(config('services.browserbase.api_key')),
        'Browserbase project ID' => filled(config('services.browserbase.project_id')),
        'Compiled browser actor' => collect([
            config('services.chef_automation.worker_path'),
            config('services.chef_automation.actor_path'),
            config('services.chef_automation.actor_launcher_path'),
        ])->every(fn ($path): bool => is_string($path) && is_file($path)),
        'Compiled Stagehand worker' => is_string(config('services.chef_automation.stagehand_worker_path'))
            && is_file(config('services.chef_automation.stagehand_worker_path')),
    ];

    $this->table(['Requirement', 'Status'], collect($checks)
        ->map(fn (bool $ready, string $label): array => [$label, $ready ? 'ready' : 'missing'])
        ->values()
        ->all());
    $this->newLine();
    $this->line('Connection flag: '.(config('automation.connection_enabled') ? 'enabled' : 'disabled'));
    $this->line('Cart mutation flag: '.(config('automation.cart_mutation_enabled') ? 'enabled' : 'disabled'));
    $this->line('Local cart-session recording: '.(
        app()->environment('local') && config('services.browserbase.record_local_cart_sessions')
            ? 'enabled'
            : 'disabled'
    ));
    $this->line(sprintf(
        'Browser viewport: %d × %d',
        (int) config('services.browserbase.viewport_width'),
        (int) config('services.browserbase.viewport_height'),
    ));
    $this->line('Normal-app sync proof: '.(config('automation.normal_app_sync_proven') ? 'recorded' : 'not recorded'));
    $this->line('Automation queue: '.config('automation.queue'));

    $providerReady = $checks['Browserbase API key'] && $checks['Browserbase project ID'];
    if (config('automation.connection_enabled') && ! $providerReady) {
        $this->error('Connection mode is enabled without complete Browserbase configuration.');

        return Command::FAILURE;
    }

    return Command::SUCCESS;
})->purpose('Report the safe configuration state of Woolworths cart preparation');
