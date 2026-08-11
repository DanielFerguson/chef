<?php

use App\Actions\Conversations\LinkAiConversation;
use App\Actions\MealPlans\DeleteMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\LaravelAiConversationEngine;
use App\Ai\Tools\ConfirmPlan;
use App\Ai\Tools\CorrectHouseholdPreference;
use App\Ai\Tools\CreateFamilyRecipe;
use App\Ai\Tools\CreateHouseholdPerson;
use App\Ai\Tools\CreateMealProposal;
use App\Ai\Tools\CreatePlanMealSlot;
use App\Ai\Tools\InspectMealPlan;
use App\Ai\Tools\InspectRecipes;
use App\Ai\Tools\InspectTeamContext;
use App\Ai\Tools\MoveSelectedMeal;
use App\Ai\Tools\RecordHouseholdPreference;
use App\Ai\Tools\RecordSafetyConstraint;
use App\Ai\Tools\RecoverableApprovableTool;
use App\Ai\Tools\RecoverableTool;
use App\Ai\Tools\SaveRetailerProductPreference;
use App\Ai\Tools\SaveRetailerPurchasePolicy;
use App\Ai\Tools\SelectPlanMeal;
use App\Ai\Tools\UpdatePlanDateSpan;
use App\Enums\MessageRole;
use App\Models\User;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\ToolNameResolver;

it('uses the laravel ai sdk fake without making a live request', function () {
    ChefAgent::fake(['Let’s work out who is home on Thursday.'])->preventStrayPrompts();

    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $message = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Tahlia is away on Thursday.',
    ]);

    $reply = app(LaravelAiConversationEngine::class)->respondTo($conversation, $message);

    expect($reply->content)->toBe('Let’s work out who is home on Thursday.')
        ->and($reply->metadata)->toHaveKey('invocation_id');

    ChefAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Tahlia is away on Thursday.'));
});

it('projects the sdk pending approval fake without executing confirm plan', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $revision = $plan->refresh()->revision;
    $conversation = $plan->conversations->firstOrFail();
    $message = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Review the plan.',
    ]);
    ChefAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_approval', 'ConfirmPlan', ['plan_revision' => $revision], 'Review required.'),
        ]),
    ])->preventStrayPrompts();

    $reply = app(LaravelAiConversationEngine::class)->respondTo($conversation, $message);

    expect($reply->metadata['pending_tool_approval'])->toMatchArray([
        'id' => 'call_approval',
        'tool' => 'ConfirmPlan',
        'plan_revision' => $revision,
    ])->and($reply->content)->toContain('approval card')
        ->and($plan->refresh()->planning_confirmed_at)->toBeNull()
        ->and($conversation->refresh()->ai_conversation_id)->not->toBeNull();
});

it('deletes the private sdk ledger with its chef plan conversation', function () {
    ChefAgent::fake(['A linked response.'])->preventStrayPrompts();
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $conversation = $plan->conversations->firstOrFail();
    $message = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Link this conversation.',
    ]);

    app(LaravelAiConversationEngine::class)->respondTo($conversation, $message);
    $aiConversationId = $conversation->refresh()->ai_conversation_id;

    expect($aiConversationId)->not->toBeNull();
    $this->assertDatabaseHas('agent_conversations', ['id' => $aiConversationId]);

    app(DeleteMealPlan::class)->handle($plan, $user);

    $this->assertDatabaseMissing('agent_conversations', ['id' => $aiConversationId]);
    $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $aiConversationId]);
});

it('loads durable chef messages as agent conversation context', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $current = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Make that lunches and dinners.',
    ]);

    $messages = collect((new ChefAgent($conversation, $current->id, $user, $current))->messages());

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->role->value)->toBe('assistant')
        ->and($messages->first()->content)->toContain("Let's plan");
});

it('links legacy context once and uses the sdk ledger for later technical history', function () {
    ChefAgent::fake(['First linked response.'])->preventStrayPrompts();
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $conversation = $plan->conversations->firstOrFail();
    $first = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Plan a quick dinner.',
    ]);

    app(LaravelAiConversationEngine::class)->respondTo($conversation, $first);
    $conversation->refresh();
    $second = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Make it vegetarian.',
    ]);
    $agent = (new ChefAgent($conversation, $second->id, $user, $second))
        ->continue($conversation->ai_conversation_id, as: $conversation);
    $contents = collect($agent->messages())->pluck('content');

    expect($conversation->ai_context_cutoff_message_id)->not->toBeNull()
        ->and($contents->filter(fn (string $content): bool => str_contains($content, "Let's plan")))->toHaveCount(1)
        ->and($contents->filter(fn (string $content): bool => $content === 'Plan a quick dinner.'))->toHaveCount(1)
        ->and($contents->filter(fn (string $content): bool => $content === 'First linked response.'))->toHaveCount(1);
});

it('prelinks one sdk ledger when stale conversation instances start separate turns', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $conversation = $plan->conversations->firstOrFail();
    $first = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Plan a quick dinner.',
    ]);
    $second = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Use the vegetables first.',
    ]);
    $firstSnapshot = $conversation->fresh();
    $secondSnapshot = $conversation->fresh();
    $link = app(LinkAiConversation::class);

    $firstLedger = $link->ensure($firstSnapshot, $first);
    $secondLedger = $link->ensure($secondSnapshot, $second);

    expect($secondLedger)->toBe($firstLedger)
        ->and($conversation->refresh()->ai_conversation_id)->toBe($firstLedger)
        ->and($conversation->ai_context_cutoff_message_id)->toBeLessThan($first->id);
    $this->assertDatabaseCount('agent_conversations', 1);
});

it('exposes only authorised first-plan domain tools to the chef agent', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $current = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Plan our dinners and remember that we dislike olives.',
    ]);

    $tools = collect((new ChefAgent($conversation, $current->id, $user, $current))->tools());

    expect($tools->every(fn (object $tool) => $tool instanceof RecoverableTool))->toBeTrue()
        ->and($tools->filter(fn (object $tool) => $tool instanceof Approvable)->values())->toHaveCount(1)
        ->and($tools->filter(fn (object $tool) => $tool instanceof Approvable)->sole())->toBeInstanceOf(RecoverableApprovableTool::class)
        ->and($tools->map(fn (Tool $tool): string => ToolNameResolver::resolve($tool))->all())->toBe([
            class_basename(InspectTeamContext::class),
            class_basename(InspectMealPlan::class),
            class_basename(InspectRecipes::class),
            class_basename(CreateHouseholdPerson::class),
            class_basename(UpdatePlanDateSpan::class),
            class_basename(CreatePlanMealSlot::class),
            class_basename(CreateMealProposal::class),
            class_basename(CreateFamilyRecipe::class),
            class_basename(SelectPlanMeal::class),
            class_basename(MoveSelectedMeal::class),
            class_basename(RecordHouseholdPreference::class),
            class_basename(SaveRetailerPurchasePolicy::class),
            class_basename(SaveRetailerProductPreference::class),
            class_basename(CorrectHouseholdPreference::class),
            class_basename(ConfirmPlan::class),
            class_basename(RecordSafetyConstraint::class),
        ]);
});

it('disables parallel openai tool calls for the mixed read and write agent', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $current = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Add milk and paper towels.',
    ]);
    $agent = new ChefAgent($conversation, $current->id, $user, $current);

    expect($agent->providerOptions(Lab::OpenAI))->toBe(['parallel_tool_calls' => false])
        ->and($agent->providerOptions(Lab::Anthropic))->toBe([]);
});

it('pins the plan conversation to OpenAI Luna for interactive drafting speed', function () {
    config()->set('ai.workloads.conversation.model', 'gpt-5.6-luna');

    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $current = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Plan dinners for the week.',
    ]);
    $agent = new ChefAgent($conversation, $current->id, $user, $current);

    expect($agent->model())->toBe('gpt-5.6-luna')
        ->and($agent->provider())->toBe(Lab::OpenAI);
});
