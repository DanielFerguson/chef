<?php

use App\Actions\Shopping\GenerateShoppingList;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
