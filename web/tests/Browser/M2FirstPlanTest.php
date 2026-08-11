<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Households\CreateHouseholdPerson;
use App\Actions\Households\RecordConstraint;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\ConstraintKind;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Models\MealSlot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;

it('creates a first plan and continues its conversation in a real browser', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $person = $team->people()->sole();
    ChefAgent::fake([
        new ToolCall('slot-call', 'CreatePlanMealSlot', [
            'date' => today()->toDateString(),
            'kind' => 'dinner',
            'participant_ids' => [$person->id],
        ]),
        fn () => new ToolCall('proposal-call', 'CreateMealProposal', [
            'title' => 'Satay chicken',
            'meal_slot_id' => MealSlot::query()->sole()->id,
            'summary' => 'Quick chicken satay with rice and broccoli.',
            'estimated_minutes' => 35,
            'estimated_cost' => 18,
        ]),
        'I have added satay chicken for you to review.',
    ])->preventStrayPrompts();
    $this->actingAs($user);

    $page = visit('/dashboard')->on()->desktop()
        ->assertSee('Welcome to Chef')
        ->click('Start a plan')
        ->assertSee('Who are we feeding')
        ->type('textarea[aria-label="Message Chef"]', 'Please plan satay chicken for our first dinner.')
        ->click('[data-testid="send-message"]')
        ->assertSee('I have added satay chicken for you to review.')
        ->assertSee('Satay chicken')
        ->assertSee('Your plan is ready to approve')
        ->pressAndWaitFor('Approve plan & prepare recipes')
        ->assertSee('Plan and recipes are ready')
        ->pressAndWaitFor('View recipes')
        ->assertSee('Satay chicken');

    $page->assertNoJavaScriptErrors();
});

it('renders persisted and streamed assistant markdown as readable content', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $plan->conversations->first()->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => "## Dinner ideas\n\n- **Satay chicken**\n- Pork katsu\n\n| Day | Time |\n| --- | ---: |\n| Monday | 30 min |\n\nUse `jasmine rice`.",
    ]);
    ChefAgent::fake([
        "## Updated options\n\n1. **Butter chicken**\n2. Beef noodles\n\nReady in `35 minutes`.",
    ])->preventStrayPrompts();
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertPresent('[data-message-role="assistant"] h2')
        ->assertPresent('[data-message-role="assistant"] ul')
        ->assertPresent('[data-message-role="assistant"] table')
        ->assertPresent('[data-message-role="assistant"] code')
        ->assertDontSee('**Satay chicken**')
        ->type('textarea[aria-label="Message Chef"]', 'Please update those options.')
        ->click('[data-testid="send-message"]')
        ->assertSee('Updated options')
        ->assertPresent('[data-message-role="assistant"] ol')
        ->assertDontSee('**Butter chicken**')
        ->assertNoJavaScriptErrors();
});

it('uses the response typeset for user and assistant messages', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $message = app(CreateUserMessage::class)->handle(
        $plan->conversations->firstOrFail(),
        $user,
        'Please suggest three quick dinners.',
        (string) Str::uuid(),
    );
    $message->update([
        'response_status' => MessageResponseStatus::Completed,
        'response_completed_at' => now(),
    ]);
    $matchesResponseTypeset = <<<'JS'
        () => {
            const userMessage = document.querySelector('[data-message-role="user"]');
            const assistantMessage = document.querySelector('[data-message-role="assistant"]');

            if (! userMessage || ! assistantMessage) {
                return false;
            }

            const userStyle = getComputedStyle(userMessage);
            const assistantStyle = getComputedStyle(assistantMessage);

            return userStyle.fontFamily === assistantStyle.fontFamily
                && userStyle.fontSize === assistantStyle.fontSize
                && userStyle.lineHeight === assistantStyle.lineHeight;
        }
        JS;
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertScript($matchesResponseTypeset)
        ->resize(390, 844)
        ->assertScript($matchesResponseTypeset)
        ->assertNoJavaScriptErrors();
});

it('shows one accessible thinking marker without persistent delivery clutter', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    ChefAgent::fake(['Dinner ideas are on the way.'])->preventStrayPrompts();
    $this->actingAs($user);

    $page = visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertScript('(window.__chefRealFetch = window.fetch.bind(window), true)')
        ->assertScript('(window.__chefPendingFetches = [], true)')
        ->assertScript('(window.fetch = (...args) => new Promise((resolve, reject) => {
            window.__chefPendingFetches.push(
                () => window.__chefRealFetch(...args).then(resolve, reject),
            );
        }), true)')
        ->typeSlowly('textarea[aria-label="Message Chef"]', 'Suggest a quick dinner.', 10)
        ->wait(0.2);

    $page->script("window.setTimeout(
        () => document.querySelector('[data-testid=send-message]').click(),
        0,
    )");

    $page->wait(0.2)
        ->assertSee('Thinking…')
        ->assertPresent('[data-slot="marker"][role="status"] [data-slot="marker-icon"][aria-hidden="true"]')
        ->assertDontSee('Chef is responding…')
        ->assertScript("() => {
            const markers = [...document.querySelectorAll('[data-slot=marker][role=status]')];
            const content = document.querySelector('[data-slot=message-scroller-content]');
            const thinking = markers.filter((marker) => marker.textContent.includes('Thinking…'));

            return thinking.length === 1
                && thinking[0].querySelector('[data-slot=marker-icon][aria-hidden=true]') !== null
                && content.getAttribute('aria-busy') === 'true'
                && window.__chefPendingFetches.length === 1;
        }");

    $page->script('(window.fetch = window.__chefRealFetch, true)');
    $page->script('window.__chefPendingFetches.splice(0).forEach((start) => start())');

    $page->assertSee('Dinner ideas are on the way.')
        ->assertDontSee('Thinking…')
        ->assertDontSee('Delivered')
        ->assertNoJavaScriptErrors();
});

it('retries a failed conversation turn from its message actions', function () {
    $user = User::factory()->create(['name' => 'Daniel Ferguson']);
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $message = app(CreateUserMessage::class)->handle(
        $plan->conversations->firstOrFail(),
        $user,
        'Please retry this dinner request.',
        (string) Str::uuid(),
    );
    $message->update([
        'response_status' => MessageResponseStatus::Failed,
        'response_error' => 'Chef could not finish that response.',
    ]);
    ChefAgent::fake([
        'Recovered — I can continue planning dinner.',
    ])->preventStrayPrompts();
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Chef could not finish that response.')
        ->assertPresent('[data-slot="marker"][role="status"] [data-slot="marker-icon"]')
        ->assertPresent('button[aria-label="Retry message"]')
        ->click('button[aria-label="Retry message"]')
        ->assertSee('Recovered — I can continue planning dinner.')
        ->assertDontSee('Delivered')
        ->assertDontSee('Chef could not finish that response.')
        ->assertMissing('button[aria-label="Retry message"]')
        ->assertPresent('button[aria-label="Copy Chef response"]')
        ->assertPresent('[data-slot="message-avatar"]')
        ->assertNoJavaScriptErrors();

    expect($message->refresh()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($message->response()->count())->toBe(1);
});

it('recovers plan-specific dinner suggestions without duplicating the failed turn', function () {
    $user = User::factory()->create(['name' => 'Daniel Ferguson']);
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->firstOrFail();
    $slots = collect();

    foreach (range(0, 6) as $offset) {
        $slots->push(app(CreateMealSlot::class)->handle(
            $plan,
            $user,
            today()->addDays($offset),
            MealSlotKind::Dinner,
            $team->people,
        ));
    }

    $source = app(CreateUserMessage::class)->handle(
        $conversation,
        $user,
        "I'd love a butter chicken, beef tacos and a satay chicken.",
        (string) Str::uuid(),
    );
    $source->update(['response_status' => MessageResponseStatus::Completed, 'response_completed_at' => now()]);

    foreach (['Butter chicken', 'Beef tacos', 'Satay chicken'] as $index => $title) {
        app(ProposeMeal::class)->handle($plan, $user, $title, $slots[$index], message: $source);
    }

    $clientId = '5c11c9b7-bf00-4c64-ac7b-38aab79e66f1';
    $message = app(CreateUserMessage::class)->handle($conversation, $user, 'Please do!', $clientId);
    $message->update([
        'response_status' => MessageResponseStatus::Failed,
        'response_error' => 'Chef could not finish that response. Retry this message.',
        'metadata' => ['response' => ['attempts' => 2]],
    ]);
    ChefAgent::fake([
        new ToolCall('inspect-plan', 'InspectMealPlan', []),
        new ToolCall('proposal-four', 'CreateMealProposal', [
            'title' => 'Lemon chicken tray bake',
            'meal_slot_id' => $slots[3]->id,
            'summary' => 'Chicken, lemon, and vegetables on one tray.',
            'estimated_minutes' => 30,
        ]),
        new ToolCall('proposal-five', 'CreateMealProposal', [
            'title' => 'Beef and broccoli noodles',
            'meal_slot_id' => $slots[4]->id,
            'summary' => 'Fast noodles with beef and crisp broccoli.',
            'estimated_minutes' => 30,
        ]),
        new ToolCall('proposal-six', 'CreateMealProposal', [
            'title' => 'Pesto chicken pasta',
            'meal_slot_id' => $slots[5]->id,
            'summary' => 'A quick pesto pasta with chicken and greens.',
            'estimated_minutes' => 30,
        ]),
        new ToolCall('proposal-seven', 'CreateMealProposal', [
            'title' => 'Fish tacos',
            'meal_slot_id' => $slots[6]->id,
            'summary' => 'Simple fish tacos with crunchy slaw.',
            'estimated_minutes' => 30,
        ]),
        'I kept your first three choices and added four more quick dinner suggestions for review.',
    ])->preventStrayPrompts();
    $this->actingAs($user);

    $page = visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Butter chicken')
        ->assertSee('Satay chicken')
        ->assertSee('Chef could not finish that response. Retry this message.')
        ->assertPresent('button[aria-label="Retry message"]')
        ->click('button[aria-label="Retry message"]')
        ->assertSee('added four more quick dinner suggestions')
        ->assertSee('Your plan is ready to approve')
        ->assertSee('Butter chicken')
        ->assertSee('Fish tacos')
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->resize(390, 844)
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertNoJavaScriptErrors();

    expect($message->refresh()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($message->metadata['response']['attempts'])->toBe(3)
        ->and($message->response()->count())->toBe(1)
        ->and($conversation->messages()->where('client_message_id', $clientId)->count())->toBe(1)
        ->and($plan->proposals()->count())->toBe(7)
        ->and($plan->plannedMeals()->count())->toBe(0);
});

it('keeps a long conversation at the live edge with the composer in view', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->first();

    foreach (range(1, 12) as $index) {
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::User,
            'user_id' => $user->id,
            'content' => "Planning note {$index} with enough detail to make this transcript scroll.",
        ]);
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::Assistant,
            'content' => "Chef response {$index} with a useful recommendation for the plan.",
        ]);
    }

    ChefAgent::fake([
        'The newest recommendation stays visible at the live edge.',
    ])->preventStrayPrompts();
    $this->actingAs($user);

    $page = visit(route('meal-plans.show', $plan))->on()->desktop()
        ->wait(1)
        ->assertPresent('[data-slot="message-scroller"]')
        ->assertPresent('[data-slot="message"]')
        ->assertPresent('[data-slot="bubble"]')
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return viewport.scrollHeight > viewport.clientHeight;
        }")
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return Math.abs(viewport.scrollHeight - viewport.clientHeight - viewport.scrollTop) < 4;
        }")
        ->assertScript('() => document.documentElement.scrollHeight <= window.innerHeight + 4')
        ->assertScript("() => {
            const composer = document.querySelector('textarea[aria-label=\"Message Chef\"]');
            const bounds = composer.getBoundingClientRect();
            return !composer.disabled && bounds.top >= 0 && bounds.bottom <= window.innerHeight;
        }");

    $page
        ->type('textarea[aria-label="Message Chef"]', 'What should we cook next?')
        ->click('[data-testid="send-message"]')
        ->assertSee('The newest recommendation stays visible at the live edge.')
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return Math.abs(viewport.scrollHeight - viewport.clientHeight - viewport.scrollTop) < 4;
        }");

    $page->script("() => {
        const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
        viewport.scrollTop = 0;
        viewport.dispatchEvent(new Event('scroll'));
    }");

    $page->wait(0.5)
        ->assertVisible('[data-testid="jump-to-latest"]')
        ->assertSee('Jump to latest')
        ->keys('[data-testid="jump-to-latest"]', 'Enter')
        ->wait(1)
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return Math.abs(viewport.scrollHeight - viewport.clientHeight - viewport.scrollTop) < 4;
        }")
        ->assertNoJavaScriptErrors();
});

it('opens a saved long response at its last meaningful user turn', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->first();

    foreach (range(1, 5) as $index) {
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::User,
            'user_id' => $user->id,
            'content' => "Earlier planning question {$index}.",
        ]);
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::Assistant,
            'content' => "Earlier planning answer {$index}.",
        ]);
    }

    $lastUserMessage = $conversation->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::User,
        'user_id' => $user->id,
        'content' => 'Give me the detailed final plan.',
    ]);
    $conversation->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => implode("\n\n", array_fill(0, 24, 'A detailed dinner recommendation with enough context to continue below the fold.')),
    ]);
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->wait(1)
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            const anchor = document.querySelector('[data-message-id=\"{$lastUserMessage->id}\"]');
            const offset = anchor.getBoundingClientRect().top - viewport.getBoundingClientRect().top;
            const distanceFromEnd = viewport.scrollHeight - viewport.clientHeight - viewport.scrollTop;
            return offset >= 24 && offset <= 120 && distanceFromEnd > 200;
        }")
        ->assertNoJavaScriptErrors();
});

it('separates a multi-day transcript in the household timezone', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->firstOrFail();
    $today = now($team->timezone)->startOfDay()->addHours(10);
    $yesterday = $today->copy()->subDay();
    $initialMessage = $conversation->messages()->firstOrFail();
    $initialMessage->forceFill(['created_at' => $yesterday->copy()->utc()])->saveQuietly();
    $todayMessage = $conversation->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::User,
        'user_id' => $user->id,
        'content' => 'This question belongs to today.',
    ]);
    $todayMessage->forceFill(['created_at' => $today->copy()->utc()])->saveQuietly();
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Yesterday')
        ->assertSee('Today')
        ->assertPresent('[data-message-id="date-'.$yesterday->toDateString().'"] [data-slot="marker"][data-variant="separator"]')
        ->assertPresent('[data-message-id="date-'.$today->toDateString().'"] [data-slot="marker"][data-variant="separator"]')
        ->assertScript("() => {
            const content = document.querySelector('[data-slot=message-scroller-content]');
            const dateRows = [...content.querySelectorAll(':scope > [data-message-id^=\"date-\"]')];
            return dateRows.length === 2
                && dateRows.every((row) => row.dataset.scrollAnchor === 'false')
                && dateRows.every((row) => !row.querySelector('[role=separator]'));
        }")
        ->assertNoJavaScriptErrors();
});

it('keeps the planning workspace usable at a narrow mobile width', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $message = app(CreateUserMessage::class)->handle(
        $plan->conversations->firstOrFail(),
        $user,
        'Retry this narrow-screen request.',
        (string) Str::uuid(),
    );
    $message->update([
        'response_status' => MessageResponseStatus::Failed,
        'response_error' => 'Chef could not finish that response.',
    ]);
    ChefAgent::fake(['Recovered on the narrow planning screen.'])->preventStrayPrompts();
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))
        ->resize(390, 844)
        ->assertPresent('[data-slot="message-scroller"]')
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertPresent('button[aria-label="Retry message"]')
        ->click('button[aria-label="Retry message"]')
        ->assertSee('Recovered on the narrow planning screen.')
        ->assertDontSee('Household truth')
        ->assertNotPresent('button[aria-label="Open plan details"]')
        ->assertNoJavaScriptErrors();

    expect($message->refresh()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($message->response()->count())->toBe(1);
});

it('resets conversation state when navigating between plans', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $first = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay(), 'First plan');
    $second = app(StartMealPlan::class)->handle($team, $user, today()->addDays(2), today()->addDays(3), 'Second plan');
    $first->conversations->first()->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => 'Only the first plan contains satay.',
    ]);
    $second->conversations->first()->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => 'Only the second plan contains katsu.',
    ]);
    $this->actingAs($user);

    visit(route('meal-plans.show', $first))->on()->desktop()
        ->assertSee('Only the first plan contains satay.')
        ->click('a[href="'.route('meal-plans.show', $second, absolute: false).'"]')
        ->assertSee('Only the second plan contains katsu.')
        ->assertDontSee('Only the first plan contains satay.')
        ->assertNoJavaScriptErrors();
});

it('keeps safety evidence in the durable conversation while changing plan views', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $conversation = $plan->conversations->first();
    $message = app(CreateUserMessage::class)->handle($conversation, $user, 'Tahlia has a severe peanut allergy.', (string) Str::uuid());
    $tahlia = app(CreateHouseholdPerson::class)->handle($team, $user, 'Tahlia', $message);
    app(RecordConstraint::class)->handle($team, $user, ConstraintKind::Allergy, 'Peanuts', confirmationMessage: $message, person: $tahlia, severity: 'severe');

    foreach (range(1, 8) as $index) {
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::User,
            'user_id' => $user->id,
            'content' => "Later planning question {$index}.",
        ]);
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::Assistant,
            'content' => "Later planning answer {$index}.",
        ]);
    }
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertPresent("[data-message-id=\"{$message->id}\"]")
        ->click('nav[aria-label="Meal plan view"] button:nth-child(2)')
        ->assertPresent('[data-testid="calendar-grid"]')
        ->click('Conversation')
        ->assertPresent("[data-message-id=\"{$message->id}\"]")
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertNoJavaScriptErrors();
});

it('keeps reject and move actions available as direct controls', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $first = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    app(CreateMealSlot::class)->handle($plan, $user, today()->addDay(), MealSlotKind::Dinner, $team->people);
    app(ProposeMeal::class)->handle($plan, $user, 'Mushroom pasta', $first);
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Mushroom pasta')
        ->click('Reject')
        ->assertDontSee('Mushroom pasta')
        ->assertNoJavaScriptErrors();
});

it('uses the photo picker and renders persisted attachments with accessible controls', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $contents = file_get_contents(base_path('tests/Fixtures/Images/iphone-pantry.heif'));

    if ($contents === false) {
        throw new RuntimeException('The HEIC browser fixture could not be read.');
    }

    app(CreateUserMessage::class)->handle(
        $plan->conversations()->firstOrFail(),
        $user,
        '',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->createWithContent('pantry.heif', $contents)],
    );
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->mobile()
        ->assertPresent('a[aria-label="Open photo 1"]')
        ->assertAttributeContains('a[aria-label="Open photo 1"]', 'href', '/message-attachments/')
        ->attach('input[type="file"]', base_path('tests/Fixtures/Images/iphone-pantry.heif'))
        ->assertSee('Photo 1')
        ->assertPresent('button[aria-label="Remove photo 1"]')
        ->wait(4)
        ->assertPresent('img[src^="blob:"]')
        ->assertEnabled('[data-testid="send-message"]')
        ->click('button[aria-label="Remove photo 1"]')
        ->assertMissing('button[aria-label="Remove photo 1"]')
        ->attach('input[type="file"]', base_path('tests/Fixtures/Images/broken-preview.heic'))
        ->assertSee('Preview unavailable — photo will still upload')
        ->assertPresent('[data-slot="attachment"][data-state="error"]')
        ->assertEnabled('[data-testid="send-message"]')
        ->click('button[aria-label="Remove photo 1"]')
        ->resize(390, 844)
        ->assertPresent('a[aria-label="Open photo 1"]')
        ->assertNoJavaScriptErrors();
});

it('retains the original photo when the first conversation request never reaches the server', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $this->actingAs($user);

    $page = visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertScript('(window.__chefRealFetch = window.fetch.bind(window), true)')
        ->assertScript('(window.__chefConversationRequests = 0, true)')
        ->assertScript('(window.fetch = (...args) => {
            const body = args[1]?.body;
            if (!(body instanceof FormData)) {
                return window.__chefRealFetch(...args);
            }

            window.__chefConversationRequests += 1;
            if (window.__chefConversationRequests === 1) {
                return Promise.reject(new TypeError("Simulated network interruption."));
            }

            window.__chefRetryPayload = {
                clientMessageId: body.get("client_message_id"),
                content: body.get("content"),
                imageCount: body.getAll("images[]").length,
                imageName: body.getAll("images[]")[0]?.name,
            };
            const ndjson = [
                JSON.stringify({ type: "delta", delta: "I can use that pantry photo for the plan." }),
                JSON.stringify({ type: "complete", delta: "", metadata: {} }),
            ].join("\n") + "\n";

            return Promise.resolve(new Response(ndjson, {
                status: 200,
                headers: { "Content-Type": "application/x-ndjson" },
            }));
        }, true)');

    $page->attach('input[type="file"]', base_path('tests/Fixtures/Images/iphone-pantry.heif'))
        ->wait(4)
        ->type('textarea[aria-label="Message Chef"]', 'Use this pantry photo.')
        ->click('[data-testid="send-message"]')
        ->assertSee('Simulated network interruption.')
        ->assertPresent('button[aria-label="Retry message"]')
        ->click('button[aria-label="Retry message"]')
        ->assertScript('() => window.__chefRetryPayload?.content === "Use this pantry photo."
            && typeof window.__chefRetryPayload.clientMessageId === "string"
            && window.__chefRetryPayload.clientMessageId.length > 0
            && window.__chefRetryPayload.imageCount === 1
            && window.__chefRetryPayload.imageName === "iphone-pantry.heif"')
        ->assertNoJavaScriptErrors();
});
