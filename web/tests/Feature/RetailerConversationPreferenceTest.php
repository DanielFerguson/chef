<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\RememberRetailerProductPreference;
use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Actions\Retailers\SelectRetailerProducts;
use App\Actions\Retailers\UpdateRetailerPurchasePolicy;
use App\Actions\Retailers\ValidateExplicitRetailerPreferenceEvidence;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Tools\SaveRetailerProductPreference;
use App\Ai\Tools\SaveRetailerPurchasePolicy;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\MessageRole;
use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\RetailerProductCandidate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request as ToolRequest;

/** @return array{user: User, team: Team, plan: MealPlan} */
function conversationPurchaseWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Conversation grocery family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());

    return compact('user', 'team', 'plan');
}

/** @param array{user: User, team: Team, plan: MealPlan} $workspace */
function conversationPreferenceMessage(array $workspace, string $content): Message
{
    return $workspace['plan']->conversations()->firstOrFail()->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => $content,
    ]);
}

/**
 * @param  array{user: User, team: Team, plan: MealPlan}  $workspace
 * @return array{run: BasketRun, requirement: GroceryRequirement, current: RetailerProductCandidate, alternative: RetailerProductCandidate}
 */
function conversationProductRun(array $workspace): array
{
    $effective = app(ResolveEffectiveRetailerPurchasePolicy::class)->handle($workspace['plan']);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', 'conversation-plan-'.Str::uuid()),
        'recipe_fingerprint' => hash('sha256', 'conversation-recipes-'.Str::uuid()),
        'purchase_policy_snapshot' => $effective['snapshot'],
        'purchase_policy_fingerprint' => $effective['fingerprint'],
        'built_at' => now(),
    ]);
    $requirement = $groceryPlan->requirements()->create([
        'team_id' => $workspace['team']->id,
        'status' => GroceryRequirementStatus::Pending,
        'display_name' => 'Pasta',
        'normalized_name' => 'pasta',
        'quantity' => 500,
        'unit' => 'g',
        'quantity_unknown' => false,
        'fingerprint' => hash('sha256', 'conversation-requirement-'.Str::uuid()),
        'search_queries' => ['pasta'],
        'applicable_constraints' => [],
    ]);
    $candidate = function (string $sku, string $title, int $price) use ($requirement): RetailerProductCandidate {
        return $requirement->candidates()->create([
            'team_id' => $requirement->team_id,
            'provider' => RetailerProvider::Coles,
            'sku' => $sku,
            'title' => $title,
            'brand' => Str::before($title, ' '),
            'semantic_key' => 'penne',
            'origin_host' => 'www.coles.com.au',
            'product_path' => '/product/'.$sku,
            'pack_quantity' => 500,
            'pack_unit' => 'g',
            'price_cents' => $price,
            'available' => true,
            'status' => RetailerCandidateStatus::Eligible,
            'rejection_codes' => [],
            'label_evidence' => [],
            'fingerprint' => hash('sha256', 'conversation-candidate-'.$sku.Str::uuid()),
            'captured_at' => now(),
        ]);
    };
    $current = $candidate('current', 'Coles Penne 500g', 100);
    $alternative = $candidate('barilla', 'Barilla Penne 500g', 110);
    $run = BasketRun::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'grocery_plan_id' => $groceryPlan->id,
        'requested_by_user_id' => $workspace['user']->id,
        'status' => BasketRunStatus::SelectingProducts,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', 'conversation-run-'.Str::uuid()),
    ]);
    app(SelectRetailerProducts::class)->handle($groceryPlan);

    return compact('run', 'requirement', 'current', 'alternative');
}

it('saves household purchasing policy from conversation only with explicit current-message language', function (string $content, bool $shouldSave) {
    $workspace = conversationPurchaseWorkspace();
    $message = conversationPreferenceMessage($workspace, $content);
    $tool = new SaveRetailerPurchasePolicy(
        $workspace['team'],
        $workspace['user'],
        $message,
        app(UpdateRetailerPurchasePolicy::class),
        app(ResolveEffectiveRetailerPurchasePolicy::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );
    $arguments = new ToolRequest([
        'home_brand_preference' => 'prefer',
        'bulk_preference' => 'avoid',
        'preferred_brands' => ['Barilla'],
        'evidence_quote' => $content,
    ]);

    if (! $shouldSave) {
        expect(fn () => $tool->handle($arguments))->toThrow(ValidationException::class)
            ->and($workspace['team']->retailerPurchasePolicies()->count())->toBe(0);

        return;
    }

    $tool->handle($arguments);

    $policy = $workspace['team']->retailerPurchasePolicies()->sole();
    expect($policy->home_brand_preference->value)->toBe('prefer')
        ->and($policy->bulk_preference->value)->toBe('avoid')
        ->and($policy->preferred_brands)->toBe(['Barilla']);
})->with([
    'explicit preference' => ['Always prefer Barilla or another home-brand option and avoid bulk packs.', true],
    'one accepted choice is not durable evidence' => ['The Barilla option in this basket looks fine.', false],
]);

it('merges a partial evidenced policy update without overwriting unrelated settings', function () {
    $workspace = conversationPurchaseWorkspace();
    app(UpdateRetailerPurchasePolicy::class)->handle(
        $workspace['team'],
        $workspace['user'],
        RetailerProvider::Coles,
        RetailerHomeBrandPreference::Prefer,
        RetailerBulkPreference::Allow,
        RetailerOrganicPreference::Prefer,
        ['Barilla'],
        12_500,
    );
    $message = conversationPreferenceMessage($workspace, 'Always avoid bulk packs.');
    $tool = new SaveRetailerPurchasePolicy(
        $workspace['team'],
        $workspace['user'],
        $message,
        app(UpdateRetailerPurchasePolicy::class),
        app(ResolveEffectiveRetailerPurchasePolicy::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );

    $tool->handle(new ToolRequest([
        'bulk_preference' => 'avoid',
        'evidence_quote' => 'Always avoid bulk packs.',
    ]));

    $policy = $workspace['team']->retailerPurchasePolicies()->sole();
    expect($policy->home_brand_preference)->toBe(RetailerHomeBrandPreference::Prefer)
        ->and($policy->bulk_preference)->toBe(RetailerBulkPreference::Avoid)
        ->and($policy->organic_preference)->toBe(RetailerOrganicPreference::Prefer)
        ->and($policy->preferred_brands)->toBe(['Barilla'])
        ->and($policy->default_basket_target_cents)->toBe(12_500);
});

it('rejects policy fields that are not supported by the quoted evidence', function () {
    $workspace = conversationPurchaseWorkspace();
    $message = conversationPreferenceMessage($workspace, 'Always avoid bulk packs.');
    $tool = new SaveRetailerPurchasePolicy(
        $workspace['team'],
        $workspace['user'],
        $message,
        app(UpdateRetailerPurchasePolicy::class),
        app(ResolveEffectiveRetailerPurchasePolicy::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );

    expect(fn () => $tool->handle(new ToolRequest([
        'home_brand_preference' => 'prefer',
        'bulk_preference' => 'avoid',
        'evidence_quote' => 'Always avoid bulk packs.',
    ])))->toThrow(ValidationException::class)
        ->and($workspace['team']->retailerPurchasePolicies()->count())->toBe(0);
});

it('rejects policy values and additions that are not supported by the quoted evidence', function (string $content, array $arguments) {
    $workspace = conversationPurchaseWorkspace();
    $message = conversationPreferenceMessage($workspace, $content);
    $tool = new SaveRetailerPurchasePolicy(
        $workspace['team'],
        $workspace['user'],
        $message,
        app(UpdateRetailerPurchasePolicy::class),
        app(ResolveEffectiveRetailerPurchasePolicy::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );

    expect(fn () => $tool->handle(new ToolRequest([
        ...$arguments,
        'evidence_quote' => $content,
    ])))->toThrow(ValidationException::class)
        ->and($workspace['team']->retailerPurchasePolicies()->count())->toBe(0);
})->with([
    'opposite direction' => [
        'Always avoid home brands.',
        ['home_brand_preference' => 'prefer'],
    ],
    'opposite direction in an adjacent clause' => [
        'Always prefer home brands, but avoid bulk packs.',
        ['home_brand_preference' => 'avoid', 'bulk_preference' => 'avoid'],
    ],
    'different basket amount' => [
        'Always keep the basket budget at $125.',
        ['default_basket_target_cents' => 20_000],
    ],
    'unmentioned additional brand' => [
        'Always prefer Barilla.',
        ['preferred_brands' => ['Barilla', 'San Remo']],
    ],
]);

it('accepts the exact evidenced basket amount', function () {
    $workspace = conversationPurchaseWorkspace();
    $content = 'Always keep the basket budget at $125.';
    $message = conversationPreferenceMessage($workspace, $content);
    $tool = new SaveRetailerPurchasePolicy(
        $workspace['team'],
        $workspace['user'],
        $message,
        app(UpdateRetailerPurchasePolicy::class),
        app(ResolveEffectiveRetailerPurchasePolicy::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );

    $tool->handle(new ToolRequest([
        'default_basket_target_cents' => 12_500,
        'evidence_quote' => $content,
    ]));

    expect($workspace['team']->retailerPurchasePolicies()->sole()->default_basket_target_cents)
        ->toBe(12_500);
});

it('saves a still-valid discovered product from conversation only when next-time intent is explicit', function (string $content, bool $shouldSave) {
    $workspace = conversationPurchaseWorkspace();
    $products = conversationProductRun($workspace);
    $message = conversationPreferenceMessage($workspace, $content);
    $tool = new SaveRetailerProductPreference(
        $workspace['plan'],
        $workspace['user'],
        $message,
        app(RememberRetailerProductPreference::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );
    $arguments = new ToolRequest([
        'basket_run_id' => $products['run']->id,
        'basket_run_item_id' => $products['run']->items()->sole()->id,
        'retailer_product_candidate_id' => $products['alternative']->id,
        'evidence_quote' => $content,
    ]);

    if (! $shouldSave) {
        expect(fn () => $tool->handle($arguments))->toThrow(ValidationException::class)
            ->and($workspace['team']->retailerProductPreferences()->count())->toBe(0);

        return;
    }

    $tool->handle($arguments);

    $preference = $workspace['team']->retailerProductPreferences()->sole();
    expect($preference->sku)->toBe('barilla')
        ->and($preference->source_message_id)->toBe($message->id)
        ->and($products['run']->items()->sole()->sku)->toBe('current');
})->with([
    'next time instruction' => ['Prefer the Barilla penne next time.', true],
    'current basket acceptance' => ['The Barilla penne is okay.', false],
]);

it('rejects a saved product that is different from the one named in the evidence', function () {
    $workspace = conversationPurchaseWorkspace();
    $products = conversationProductRun($workspace);
    $content = 'Prefer the Barilla penne next time.';
    $message = conversationPreferenceMessage($workspace, $content);
    $tool = new SaveRetailerProductPreference(
        $workspace['plan'],
        $workspace['user'],
        $message,
        app(RememberRetailerProductPreference::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );

    expect(fn () => $tool->handle(new ToolRequest([
        'basket_run_id' => $products['run']->id,
        'basket_run_item_id' => $products['run']->items()->sole()->id,
        'retailer_product_candidate_id' => $products['current']->id,
        'evidence_quote' => $content,
    ])))->toThrow(ValidationException::class)
        ->and($workspace['team']->retailerProductPreferences()->count())->toBe(0);
});

it('requires the exact product rather than a brand-only product preference', function () {
    $workspace = conversationPurchaseWorkspace();
    $products = conversationProductRun($workspace);
    $content = 'Always prefer Barilla next time.';
    $message = conversationPreferenceMessage($workspace, $content);
    $tool = new SaveRetailerProductPreference(
        $workspace['plan'],
        $workspace['user'],
        $message,
        app(RememberRetailerProductPreference::class),
        app(ValidateExplicitRetailerPreferenceEvidence::class),
    );

    expect(fn () => $tool->handle(new ToolRequest([
        'basket_run_id' => $products['run']->id,
        'basket_run_item_id' => $products['run']->items()->sole()->id,
        'retailer_product_candidate_id' => $products['alternative']->id,
        'evidence_quote' => $content,
    ])))->toThrow(ValidationException::class)
        ->and($workspace['team']->retailerProductPreferences()->count())->toBe(0);
});
