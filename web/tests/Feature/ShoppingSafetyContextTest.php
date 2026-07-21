<?php

use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RemoveConstraint;
use App\Actions\Households\UpdateConstraint;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Planning\UpdateMealSlotParticipants;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Enums\ConstraintKind;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListGenerationStatus;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{user: User, team: Team, first: Person, second: Person, first_plan: MealPlan, second_plan: MealPlan} */
function shoppingSafetyWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Safety family');
    $first = $team->people()->firstOrFail();
    $second = Person::factory()->for($team)->create(['name' => 'Second participant']);
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Simple dinner',
        null,
        1,
        null,
        null,
        [['name' => 'Carrot', 'quantity' => 1, 'unit' => 'each']],
        [['instruction' => 'Cook.']],
    );

    $makePlan = function (Person $person, int $offset) use ($team, $user, $recipe): MealPlan {
        $date = today()->addDays($offset);
        $plan = app(StartMealPlan::class)->handle($team, $user, $date, $date);
        $slot = app(CreateMealSlot::class)->handle($plan, $user, $date, MealSlotKind::Dinner, [$person]);
        app(SelectPlannedMeal::class)->handle($slot, $user, PlannedMealType::Recipe, $recipe->latestVersion, servings: 1);
        app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);
        app(ConfirmMealPlan::class)->handle($plan->refresh(), $user);

        return $plan->refresh();
    };

    return [
        'user' => $user,
        'team' => $team,
        'first' => $first,
        'second' => $second,
        'first_plan' => $makePlan($first, 1),
        'second_plan' => $makePlan($second, 2),
    ];
}

it('invalidates only participant-scoped plans and every relevant plan for team constraints', function () {
    $workspace = shoppingSafetyWorkspace();
    $firstRevision = $workspace['first_plan']->revision;
    $secondRevision = $workspace['second_plan']->revision;

    $personConstraint = app(RecordConstraint::class)->handle(
        $workspace['team'],
        $workspace['user'],
        ConstraintKind::Allergy,
        'Peanuts',
        directlyConfirmed: true,
        person: $workspace['first'],
        severity: 'severe',
    );

    expect($workspace['first_plan']->refresh()->revision)->toBe($firstRevision + 1)
        ->and($workspace['second_plan']->refresh()->revision)->toBe($secondRevision)
        ->and(app(AssessMealPlanReadiness::class)->handle($workspace['first_plan'])['safety_review_required'])->toBeTrue()
        ->and(app(AssessMealPlanReadiness::class)->handle($workspace['second_plan'])['safety_review_required'])->toBeFalse();

    app(UpdateConstraint::class)->handle($personConstraint, $workspace['user'], 'Peanuts and traces', null, 'severe');
    expect($workspace['first_plan']->refresh()->revision)->toBe($firstRevision + 2);
    app(RemoveConstraint::class)->handle($personConstraint->refresh(), $workspace['user']);
    expect($workspace['first_plan']->refresh()->revision)->toBe($firstRevision + 3);

    app(RecordConstraint::class)->handle(
        $workspace['team'],
        $workspace['user'],
        ConstraintKind::Other,
        'No alcohol',
        directlyConfirmed: true,
    );
    expect($workspace['first_plan']->refresh()->revision)->toBe($firstRevision + 4)
        ->and($workspace['second_plan']->refresh()->revision)->toBe($secondRevision + 1);
});

it('blocks a missing safety hash before drafting and allows explicit reconfirmation', function () {
    $workspace = shoppingSafetyWorkspace();
    $workspace['first_plan']->update(['confirmed_safety_context_hash' => null]);
    $spy = new class implements ShoppingListDrafter
    {
        public int $calls = 0;

        public function draft(ShoppingListDraftRequest $request): ShoppingListDraft
        {
            $this->calls++;

            return new ShoppingListDraft([]);
        }
    };
    app()->instance(ShoppingListDrafter::class, $spy);

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['first_plan']->refresh(), $workspace['user']))
        ->toThrow(ValidationException::class);
    expect($spy->calls)->toBe(0)
        ->and(app(AssessMealPlanReadiness::class)->handle($workspace['first_plan']->refresh())['ready_for_safety_confirmation'])->toBeTrue();

    app(ConfirmMealPlan::class)->handle($workspace['first_plan']->refresh(), $workspace['user']);
    expect(app(AssessMealPlanReadiness::class)->handle($workspace['first_plan']->refresh())['safety_review_required'])->toBeFalse();
});

it('includes participant assignments in the confirmed safety boundary', function () {
    $workspace = shoppingSafetyWorkspace();
    $slot = $workspace['first_plan']->slots()->sole();

    app(UpdateMealSlotParticipants::class)->handle($slot, $workspace['user'], [
        $workspace['first']->id => 1,
        $workspace['second']->id => 1,
    ]);
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['first_plan']->refresh());

    expect($readiness['safety_review_required'])->toBeTrue()
        ->and($readiness['ready_for_safety_confirmation'])->toBeFalse();
    app(ReviewMealPlanSafety::class)->handle($workspace['first_plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['first_plan']->refresh(), $workspace['user']);
    expect(app(AssessMealPlanReadiness::class)->handle($workspace['first_plan']->refresh())['safety_review_required'])->toBeFalse()
        ->and($workspace['first_plan']->shoppingList?->generation_status)->not->toBe(ShoppingListGenerationStatus::Ready);
});
