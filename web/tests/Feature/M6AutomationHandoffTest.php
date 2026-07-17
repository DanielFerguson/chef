<?php

use App\Actions\Automation\AttachBrowserTab;
use App\Actions\Automation\ClaimBrowserConnection;
use App\Actions\Automation\ClaimNextAutomationStep;
use App\Actions\Automation\ControlAutomationRun;
use App\Actions\Automation\CreateBrowserPairing;
use App\Actions\Automation\DecideAutomationApproval;
use App\Actions\Automation\ExpireAutomationArtifacts;
use App\Actions\Automation\StartCartPreparation;
use App\Actions\Automation\SubmitAutomationStepResult;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Automation\Data\ComputerUseStep;
use App\Automation\ResponsesComputerUseEngine;
use App\Automation\Testing\FakeComputerUseEngine;
use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationReconciliationStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Enums\BrowserConnectionStatus;
use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use App\Models\Budget;
use App\Models\MealPlan;
use App\Models\ProductPreference;
use App\Models\Retailer;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function m6Png(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
}

/** @return array{user: User, team: Team, plan: MealPlan, list: ShoppingList, items: Collection<int, ShoppingListItem>} */
function m6Workspace(array $names = ['Milk', 'Paper towels']): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Automation Family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $plan->update(['planning_confirmed_at' => now()]);
    $plan->refresh();
    $list = ShoppingList::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $user->id,
        'revision' => 2,
        'source_plan_revision' => $plan->revision,
        'status' => ShoppingListStatus::Draft,
    ]);

    foreach ($names as $position => $name) {
        $list->items()->create([
            'team_id' => $team->id,
            'created_by_user_id' => $user->id,
            'source_kind' => ShoppingListItemSourceKind::Manual,
            'category' => ShoppingListItemCategory::classify($name),
            'name' => $name,
            'normalized_name' => strtolower($name),
            'quantity' => 1,
            'unit' => 'pack',
            'included' => true,
            'position' => $position + 1,
        ]);
    }

    return compact('user', 'team', 'plan', 'list') + ['items' => $list->items()->get()];
}

/** @return array{connection: BrowserConnection, token: string} */
function m6Connection(array $workspace): array
{
    $pairing = app(CreateBrowserPairing::class)->handle($workspace['team'], $workspace['user']);

    return app(ClaimBrowserConnection::class)->handle($pairing['pairing_code'], 'Kitchen Chrome');
}

function m6Run(array $workspace, BrowserConnection $connection, string $retailerSlug = 'woolworths'): AutomationRun
{
    $retailer = Retailer::query()->where('slug', $retailerSlug)->sole();

    return app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['user'],
        $retailer,
        $connection,
        $workspace['list']->revision,
    );
}

beforeEach(function () {
    Storage::fake('local');
    app(FakeComputerUseEngine::class)->reset();
});

it('pairs a browser with one-time hashed credentials and never returns them from the model', function () {
    $workspace = m6Workspace();
    $pairing = app(CreateBrowserPairing::class)->handle($workspace['team'], $workspace['user']);

    expect($pairing['connection']->status)->toBe(BrowserConnectionStatus::Pending)
        ->and($pairing['connection']->pairing_code_hash)->not->toBe($pairing['pairing_code'])
        ->and($pairing['connection']->toArray())->not->toHaveKeys(['pairing_code_hash', 'token_hash']);

    $claimed = app(ClaimBrowserConnection::class)->handle($pairing['pairing_code'], 'Kitchen Chrome');

    expect($claimed['connection']->status)->toBe(BrowserConnectionStatus::Active)
        ->and($claimed['connection']->pairing_code_hash)->toBeNull()
        ->and($claimed['connection']->token_hash)->not->toBe($claimed['token'])
        ->and(fn () => app(ClaimBrowserConnection::class)->handle($pairing['pairing_code'], 'Replay'))
        ->toThrow(ValidationException::class);
});

it('authenticates extension requests with the connection token', function () {
    $workspace = m6Workspace();
    $pairing = app(CreateBrowserPairing::class)->handle($workspace['team'], $workspace['user']);

    $this->postJson('/api/extension/connections/claim', [
        'pairing_code' => $pairing['pairing_code'],
        'name' => 'Kitchen Chrome',
    ])->assertOk()->assertJsonPath('connection.name', 'Kitchen Chrome');

    $this->getJson('/api/extension/runs/awaiting')->assertUnauthorized();
    $token = BrowserConnection::query()->sole()->token_hash;
    expect($token)->not->toBeNull();
});

it('freezes an exact authorised shopping scope for the selected retailer', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $workspace['list']->items()->firstOrFail()->update(['name' => 'Changed after start']);

    expect($run->status)->toBe(AutomationRunStatus::AwaitingBrowser)
        ->and($run->shopping_list_revision)->toBe(2)
        ->and($run->scopeItems())->toHaveCount(2)
        ->and($run->scopeItems()[0]['name'])->toBe('Milk')
        ->and($run->retailer->slug)->toBe('woolworths');
});

it('refuses stale lists, changed revisions, foreign connections, and unsupported retailers', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $workspace['list']->update(['stale_at' => now(), 'stale_reason' => 'Plan changed']);

    expect(fn () => m6Run($workspace, $claimed['connection']))->toThrow(ValidationException::class);

    $workspace['list']->update(['stale_at' => null, 'stale_reason' => null]);
    expect(fn () => app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['user'],
        Retailer::query()->where('slug', 'coles')->sole(),
        $claimed['connection'],
        1,
    ))->toThrow(ValidationException::class);

    $other = m6Workspace(['Bread']);
    expect(fn () => m6Run($workspace, m6Connection($other)['connection']))->toThrow(ValidationException::class);

    $unsupported = Retailer::query()->create(['name' => 'Other', 'slug' => 'other', 'active' => true]);
    expect(fn () => app(StartCartPreparation::class)->handle(
        $workspace['list'], $workspace['user'], $unsupported, $claimed['connection'], 2,
    ))->toThrow(ValidationException::class);
});

it('runs a recorded computer loop through an explicitly selected retailer tab', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $milk = $workspace['items']->first();
    app(FakeComputerUseEngine::class)->queue(
        new ComputerUseStep('resp_1', 'call_1', [
            ['type' => 'click', 'x' => 100, 'y' => 120],
            ['type' => 'type', 'x' => 100, 'y' => 120, 'text' => 'milk'],
        ]),
        new ComputerUseStep('resp_2', null, reconciliation: [[
            'shopping_list_item_id' => $milk->id,
            'status' => 'matched',
            'intended_name' => 'Milk',
            'product_name' => 'Woolworths Full Cream Milk 2L',
            'quantity' => 1,
            'unit_price' => 3.10,
            'total_price' => 3.10,
            'confidence' => 0.98,
        ]], message: 'Cart ready for review.', complete: true),
    );

    app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '42',
        'https://www.woolworths.com.au/shop/browse', m6Png(),
    );
    $run->refresh();

    expect($run->status)->toBe(AutomationRunStatus::Executing)
        ->and($run->current_tab_id)->toBe('42');

    $step = app(ClaimNextAutomationStep::class)->handle($claimed['connection']);
    expect($step)->not->toBeNull()->and($step->status)->toBe(AutomationStepStatus::Executing);

    app(SubmitAutomationStepResult::class)->handle(
        $step, $claimed['connection'],
        'https://www.woolworths.com.au/shop/cart', m6Png(), ['ok' => true],
    );
    $run->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingReview)
        ->and($run->previous_response_id)->toBe('resp_2')
        ->and($run->reconciliations()->count())->toBe(2)
        ->and($run->reconciliations()->where('status', AutomationReconciliationStatus::Matched)->sole()->product_name)->toContain('Milk')
        ->and($run->reconciliations()->where('status', AutomationReconciliationStatus::Unresolved)->sole()->intended_name)->toBe('Paper towels')
        ->and(app(FakeComputerUseEngine::class)->runIds())->toBe([$run->id, $run->id]);
});

it('restricts attachment and execution to the selected tab and retailer origin', function () {
    Queue::fake();
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);

    expect(fn () => app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '42', 'https://example.com/cart', m6Png(),
    ))->toThrow(ValidationException::class);
    expect(fn () => app(AttachBrowserTab::class)->handle(
        $run->refresh()->load('retailer'), $claimed['connection'], '42', 'https://www.woolworths.com.au/checkout', m6Png(),
    ))->toThrow(ValidationException::class);

    app(AttachBrowserTab::class)->handle(
        $run->refresh()->load('retailer'), $claimed['connection'], '42', 'https://www.woolworths.com.au/shop/browse', m6Png(),
    );
    $run->refresh()->update(['status' => AutomationRunStatus::Executing]);
    $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 1,
        'response_id' => 'resp',
        'call_id' => 'call',
        'status' => AutomationStepStatus::Ready,
        'actions' => [['type' => 'click', 'x' => 1, 'y' => 1]],
    ]);
    $step = app(ClaimNextAutomationStep::class)->handle($claimed['connection']);

    expect(fn () => app(SubmitAutomationStepResult::class)->handle(
        $step, $claimed['connection'], 'https://www.coles.com.au/cart', m6Png(), ['ok' => true],
    ))->toThrow(ValidationException::class);
});

it('pauses computer actions for explicit model safety approval', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    app(FakeComputerUseEngine::class)->queue(new ComputerUseStep(
        'resp_safe', 'call_safe', [['type' => 'click', 'x' => 20, 'y' => 20]],
        [['id' => 'safe_1', 'code' => 'material_change', 'message' => 'Replace the selected pack size?']],
    ));

    app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '8', 'https://www.woolworths.com.au/shop/cart', m6Png(),
    );
    $run->refresh();
    $approval = $run->approvals()->sole();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingApproval)
        ->and($approval->status)->toBe(AutomationApprovalStatus::Pending)
        ->and($run->steps()->where('sequence', 1)->sole()->status)->toBe(AutomationStepStatus::AwaitingApproval)
        ->and(app(ClaimNextAutomationStep::class)->handle($claimed['connection']))->toBeNull();

    app(DecideAutomationApproval::class)->handle($approval, $workspace['user'], true);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Executing)
        ->and($run->steps()->where('sequence', 1)->sole()->status)->toBe(AutomationStepStatus::Ready);
});

it('hands control back when an approval is rejected', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    app(FakeComputerUseEngine::class)->queue(new ComputerUseStep(
        'resp_safe', 'call_safe', [['type' => 'click', 'x' => 20, 'y' => 20]],
        [['code' => 'uncertain_product', 'message' => 'Choose an uncertain product?']],
    ));
    app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '8', 'https://www.woolworths.com.au/shop/cart', m6Png(),
    );

    app(DecideAutomationApproval::class)->handle($run->approvals()->sole(), $workspace['user'], false);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Takeover)
        ->and($run->takeover_at)->not->toBeNull();
});

it('stops immediately on checkout, authentication, address, delivery, and payment paths', function (string $path) {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    app(FakeComputerUseEngine::class)->queue(new ComputerUseStep('resp_1', 'call_1', [['type' => 'click', 'x' => 10, 'y' => 10]]));
    app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '7', 'https://www.woolworths.com.au/shop/cart', m6Png(),
    );
    $step = app(ClaimNextAutomationStep::class)->handle($claimed['connection']);

    app(SubmitAutomationStepResult::class)->handle(
        $step, $claimed['connection'], "https://www.woolworths.com.au/$path", m6Png(), ['ok' => true],
    );

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Takeover)
        ->and($run->pause_reason)->toContain('stopped before checkout');
})->with(['checkout', 'login', 'address', 'delivery-slot', 'payment']);

it('fails safely when the extension cannot execute a validated action', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    app(FakeComputerUseEngine::class)->queue(new ComputerUseStep('resp_1', 'call_1', [['type' => 'click', 'x' => 10, 'y' => 10]]));
    app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '7', 'https://www.woolworths.com.au/shop/cart', m6Png(),
    );
    $step = app(ClaimNextAutomationStep::class)->handle($claimed['connection']);

    app(SubmitAutomationStepResult::class)->handle(
        $step, $claimed['connection'], 'https://www.woolworths.com.au/shop/cart', m6Png(), ['ok' => false, 'error' => 'Element disappeared'],
    );

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Failed)
        ->and($step->refresh()->status)->toBe(AutomationStepStatus::Failed)
        ->and($run->error_code)->toBe('extension_action_failed');
});

it('supports pause, safe resume, takeover, completion, cancellation, and expiry', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);

    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'pause');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Paused);
    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'resume');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::AwaitingBrowser);
    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'takeover');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Takeover);
    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'resume');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::AwaitingBrowser)
        ->and($run->previous_response_id)->toBeNull()
        ->and($run->pause_reason)->toContain('Re-select');
    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'takeover');
    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'cancel');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Cancelled);

    $expired = m6Run($workspace, $claimed['connection'], 'coles');
    $expired->update(['expires_at' => now()->subMinute()]);
    $result = app(ExpireAutomationArtifacts::class)->handle();
    expect($result['runs'])->toBe(1)->and($expired->refresh()->status)->toBe(AutomationRunStatus::Expired);
});

it('interrupts an executing extension step when the household pauses or cancels', function () {
    Queue::fake();
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $run->update(['status' => AutomationRunStatus::Executing, 'current_tab_id' => '42']);
    $step = $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 0,
        'status' => AutomationStepStatus::Executing,
        'actions' => [['type' => 'wait', 'duration_ms' => 10_000]],
    ]);

    $this->withHeader('X-Chef-Connection-Token', $claimed['token'])
        ->getJson("/api/extension/steps/{$step->id}/control")
        ->assertOk()
        ->assertJsonPath('continue', true);

    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'pause');

    $this->withHeader('X-Chef-Connection-Token', $claimed['token'])
        ->getJson("/api/extension/steps/{$step->id}/control")
        ->assertOk()
        ->assertJsonPath('continue', false)
        ->assertJsonPath('run_status', AutomationRunStatus::Paused->value)
        ->assertJsonPath('step_status', AutomationStepStatus::Failed->value);

    app(ControlAutomationRun::class)->handle($run->refresh(), $workspace['user'], 'resume');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Queued);

    $run->update(['status' => AutomationRunStatus::Executing]);
    $secondStep = $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 1,
        'status' => AutomationStepStatus::Executing,
        'actions' => [['type' => 'click', 'x' => 1, 'y' => 1]],
    ]);
    app(ControlAutomationRun::class)->handle($run->refresh(), $workspace['user'], 'cancel');

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Cancelled)
        ->and($secondStep->refresh()->status)->toBe(AutomationStepStatus::Failed);
});

it('requires review for budget overruns and disallowed substitutions', function () {
    $workspace = m6Workspace(['Milk']);
    Budget::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'scope_key' => 'plan:'.$workspace['plan']->id,
        'set_by_user_id' => $workspace['user']->id,
        'amount' => 5,
        'currency' => 'AUD',
    ]);
    ProductPreference::query()->create([
        'team_id' => $workspace['team']->id,
        'identity_key' => hash('sha256', 'milk'),
        'normalized_item_name' => 'milk',
        'accept_substitutes' => false,
    ]);
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    app(FakeComputerUseEngine::class)->queue(new ComputerUseStep(
        'resp_final', null, reconciliation: [[
            'shopping_list_item_id' => $workspace['items']->first()->id,
            'status' => 'substituted',
            'intended_name' => 'Milk',
            'product_name' => 'Premium Milk 2L',
            'quantity' => 4,
            'unit_price' => 3,
            'total_price' => 12,
            'substitution_reason' => 'Original unavailable',
            'confidence' => 0.9,
        ]], complete: true,
    ));

    app(AttachBrowserTab::class)->handle(
        $run->load('retailer'), $claimed['connection'], '1', 'https://www.woolworths.com.au/shop/cart', m6Png(),
    );
    $run->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingApproval)
        ->and($run->approvals()->where('risk_kind', 'budget_exceeded')->exists())->toBeTrue()
        ->and($run->approvals()->where('risk_kind', 'material_substitution')->exists())->toBeTrue();

    foreach ($run->approvals()->get() as $approval) {
        app(DecideAutomationApproval::class)->handle($approval, $workspace['user'], true);
    }

    expect($run->refresh()->status)->toBe(AutomationRunStatus::AwaitingReview);
    app(ControlAutomationRun::class)->handle($run, $workspace['user'], 'complete');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Completed);
});

it('uses the current Responses computer protocol without exposing the key to the extension', function () {
    $workspace = m6Workspace(['Milk']);
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    Storage::disk('local')->put('automation/initial.png', base64_decode(substr(m6Png(), strpos(m6Png(), ',') + 1)));
    $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 0,
        'status' => AutomationStepStatus::Observed,
        'current_url' => 'https://www.woolworths.com.au/shop/browse',
        'screenshot_path' => 'automation/initial.png',
        'screenshot_expires_at' => now()->addHour(),
    ]);
    Http::fakeSequence()
        ->push([
            'id' => 'resp_protocol_1',
            'output' => [[
                'type' => 'computer_call',
                'call_id' => 'call_protocol_1',
                'actions' => [['type' => 'click', 'x' => 20, 'y' => 30]],
            ]],
        ])
        ->push([
            'id' => 'resp_protocol_2',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => '{"message":"Ready","reconciliation":[]}',
                ]],
            ]],
        ]);
    $engine = app(ResponsesComputerUseEngine::class);

    $first = $engine->advance($run->load('retailer', 'steps'));
    expect($first->actions)->toHaveCount(1)->and($first->callId)->toBe('call_protocol_1');
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'gpt-5.6'
        && $request['tools'][0]['type'] === 'computer'
        && ! isset($request['previous_response_id'])
        && str_contains($request['input'][0]['content'][0]['text'], 'Never open checkout'));

    $run->update(['previous_response_id' => 'resp_protocol_1']);
    $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 1,
        'response_id' => 'resp_protocol_1',
        'call_id' => 'call_protocol_1',
        'status' => AutomationStepStatus::Completed,
        'current_url' => 'https://www.woolworths.com.au/shop/cart',
        'screenshot_path' => 'automation/initial.png',
        'screenshot_expires_at' => now()->addHour(),
        'safety_checks' => [['id' => 'safe_protocol_1', 'code' => 'confirm', 'message' => 'Continue?']],
    ]);
    $second = $engine->advance($run->refresh()->load('retailer', 'steps'));

    expect($second->complete)->toBeTrue()->and($second->message)->toBe('Ready');
    Http::assertSent(fn (Request $request): bool => ($request['previous_response_id'] ?? null) === 'resp_protocol_1'
        && $request['input'][0]['type'] === 'computer_call_output'
        && $request['input'][0]['call_id'] === 'call_protocol_1'
        && $request['input'][0]['acknowledged_safety_checks'][0]['id'] === 'safe_protocol_1'
        && $request['input'][0]['output']['detail'] === 'original');
});

it('loads recorded Woolworths and Coles continuations without live retailer or OpenAI calls', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $woolworths = m6Run($workspace, $claimed['connection']);
    $coles = m6Run($workspace, $claimed['connection'], 'coles');
    $fake = app(FakeComputerUseEngine::class)
        ->loadFixture(base_path('tests/Fixtures/computer-use/woolworths.json'))
        ->loadFixture(base_path('tests/Fixtures/computer-use/coles.json'));

    expect($fake->advance($woolworths)->actions)->toHaveCount(3)
        ->and($fake->advance($woolworths)->complete)->toBeTrue()
        ->and($fake->advance($coles)->actions)->toHaveCount(3)
        ->and($fake->advance($coles)->complete)->toBeTrue();
});

it('expires retained screenshots and pending approvals', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    Storage::disk('local')->put('automation/old.png', 'png');
    $step = $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 0,
        'status' => AutomationStepStatus::Observed,
        'screenshot_path' => 'automation/old.png',
        'screenshot_expires_at' => now()->subMinute(),
    ]);
    $approval = $run->approvals()->create([
        'team_id' => $run->team_id,
        'risk_kind' => 'test',
        'proposed_action' => 'Test',
        'consequence' => 'Test',
        'status' => AutomationApprovalStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    $result = app(ExpireAutomationArtifacts::class)->handle();

    expect($result['screenshots'])->toBe(1)
        ->and($result['approvals'])->toBe(1)
        ->and($step->refresh()->screenshot_path)->toBeNull()
        ->and(Storage::disk('local')->exists('automation/old.png'))->toBeFalse()
        ->and($approval->refresh()->status)->toBe(AutomationApprovalStatus::Expired);
});

it('hands stalled extension work back for review instead of leaving a hanging step', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $run->update(['status' => AutomationRunStatus::Executing]);
    $step = $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 0,
        'status' => AutomationStepStatus::Executing,
        'actions' => [['type' => 'click', 'x' => 1, 'y' => 1]],
    ]);
    DB::table('automation_steps')->where('id', $step->id)->update(['updated_at' => now()->subMinutes(6)]);

    $result = app(ExpireAutomationArtifacts::class)->handle();

    expect($result['stalled_steps'])->toBe(1)
        ->and($step->refresh()->status)->toBe(AutomationStepStatus::Failed)
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::Takeover)
        ->and($run->error_code)->toBe('extension_result_timeout');
});

it('broadcasts review details and failures without exposing browser artifacts', function () {
    $workspace = m6Workspace(['Milk']);
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $run->reconciliations()->create([
        'team_id' => $run->team_id,
        'shopping_list_item_id' => $workspace['items']->first()->id,
        'status' => AutomationReconciliationStatus::Substituted,
        'intended_name' => 'Milk',
        'product_name' => 'Alternate milk',
        'substitution_reason' => 'Preferred pack unavailable',
    ]);
    $run->update([
        'status' => AutomationRunStatus::Failed,
        'error_code' => 'test_failure',
        'error_message' => 'Stopped safely.',
    ]);

    $payload = (new AutomationRunUpdated($run->refresh()))->broadcastWith();

    expect($payload['failure']['code'])->toBe('test_failure')
        ->and($payload['reconciliation'][0]['status'])->toBe('substituted')
        ->and($payload)->not->toHaveKeys(['screenshot', 'scope_snapshot', 'previous_response_id']);
});

it('enforces team isolation for every automation resource and route', function () {
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $step = $run->steps()->create(['team_id' => $run->team_id, 'sequence' => 0, 'status' => AutomationStepStatus::Observed]);
    $approval = $run->approvals()->create([
        'team_id' => $run->team_id,
        'automation_step_id' => $step->id,
        'risk_kind' => 'test',
        'proposed_action' => 'Test',
        'consequence' => 'Test',
        'status' => AutomationApprovalStatus::Pending,
        'expires_at' => now()->addMinute(),
    ]);
    $reconciliation = $run->reconciliations()->create([
        'team_id' => $run->team_id,
        'shopping_list_item_id' => $workspace['items']->first()->id,
        'status' => AutomationReconciliationStatus::Matched,
        'intended_name' => 'Milk',
    ]);
    $other = m6Workspace(['Bread']);

    foreach ([$claimed['connection'], $run, $step, $approval, $reconciliation] as $resource) {
        expect($workspace['user']->can('view', $resource))->toBeTrue()
            ->and($other['user']->can('view', $resource))->toBeFalse();
    }

    $this->actingAs($other['user'])
        ->put(route('automation-runs.update', $run), ['control' => 'cancel'])
        ->assertNotFound();
    $this->actingAs($other['user'])
        ->put(route('automation-approvals.update', $approval), ['decision' => 'approve'])
        ->assertNotFound();
});

it('rejects foreign extension tokens even when run and step identifiers are known', function () {
    Queue::fake();
    $workspace = m6Workspace();
    $claimed = m6Connection($workspace);
    $run = m6Run($workspace, $claimed['connection']);
    $step = $run->steps()->create([
        'team_id' => $run->team_id,
        'sequence' => 0,
        'status' => AutomationStepStatus::Executing,
    ]);
    $foreign = m6Connection(m6Workspace(['Bread']));

    $this->withHeader('X-Chef-Connection-Token', $foreign['token'])
        ->getJson("/api/extension/steps/{$step->id}/control")
        ->assertForbidden();

    $this->withHeader('X-Chef-Connection-Token', $foreign['token'])
        ->postJson("/api/extension/runs/{$run->uuid}/attach", [
            'tab_id' => '1',
            'current_url' => 'https://www.woolworths.com.au/shop/browse',
            'screenshot' => m6Png(),
        ])
        ->assertForbidden();
});
