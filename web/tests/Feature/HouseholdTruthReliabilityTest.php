<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Households\CorrectPreference;
use App\Actions\Households\CreateHouseholdPerson;
use App\Actions\Households\RecordPreference;
use App\Actions\Households\ValidatePreferenceEvidence;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\Tools\RecordHouseholdPreference;
use App\Enums\MessageRole;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request as ToolRequest;

uses(RefreshDatabase::class);

function truthReliabilityWorkspace(): array
{
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Daniel and Tahlia');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->firstOrFail();
    $source = app(CreateUserMessage::class)->handle(
        $conversation,
        $user,
        "Tahlia doesn't like fish or sausages.",
        (string) Str::uuid(),
    );
    $tahlia = app(CreateHouseholdPerson::class)->handle($team, $user, 'Tahlia', $source);
    $guest = app(CreateHouseholdPerson::class)->handle($team, $user, 'Dinner guest', $source);

    return compact('user', 'team', 'plan', 'conversation', 'source', 'tahlia', 'guest');
}

function preferenceTool(array $workspace, Message $message): RecordHouseholdPreference
{
    return new RecordHouseholdPreference(
        $workspace['team'],
        $workspace['user'],
        $message,
        app(RecordPreference::class),
        app(ValidatePreferenceEvidence::class),
    );
}

it('rejects a stated preference assigned to a person not supported by the quoted message', function () {
    $workspace = truthReliabilityWorkspace();

    preferenceTool($workspace, $workspace['source'])->handle(new ToolRequest([
        'scope' => 'person',
        'person_id' => $workspace['guest']->id,
        'person_reference' => 'Tahlia',
        'subject' => 'fish',
        'sentiment' => 'dislike',
        'provenance' => 'stated',
        'evidence_quote' => "Tahlia doesn't like fish",
    ]));
})->throws(ValidationException::class, 'Use the person’s name');

it('records a named preference with exact human evidence only once', function () {
    $workspace = truthReliabilityWorkspace();
    $arguments = [
        'scope' => 'person',
        'person_id' => $workspace['tahlia']->id,
        'person_reference' => 'Tahlia',
        'subject' => 'fish',
        'sentiment' => 'dislike',
        'provenance' => 'stated',
        'evidence_quote' => "Tahlia doesn't like fish",
    ];

    preferenceTool($workspace, $workspace['source'])->handle(new ToolRequest($arguments));
    preferenceTool($workspace, $workspace['source'])->handle(new ToolRequest([...$arguments, 'subject' => 'Fish']));

    $preference = $workspace['tahlia']->preferences()->sole();
    expect($preference->subject)->toBe('Fish')
        ->and($preference->source_message_id)->toBe($workspace['source']->id)
        ->and($preference->evidence_quote)->toBe("Tahlia doesn't like fish")
        ->and($workspace['team']->preferences()->count())->toBe(1);
});

it('resolves an immediate unambiguous pronoun but rejects unrelated later evidence', function () {
    $workspace = truthReliabilityWorkspace();
    $workspace['conversation']->messages()->create([
        'team_id' => $workspace['team']->id,
        'role' => MessageRole::Assistant,
        'content' => 'I noted that Tahlia dislikes fish and sausages.',
        'in_reply_to_message_id' => $workspace['source']->id,
    ]);
    $pestoMessage = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        "She's also not a massive fan of pesto.",
        (string) Str::uuid(),
    );

    preferenceTool($workspace, $pestoMessage)->handle(new ToolRequest([
        'scope' => 'person',
        'person_id' => $workspace['tahlia']->id,
        'person_reference' => 'She',
        'subject' => 'pesto',
        'sentiment' => 'dislike',
        'provenance' => 'stated',
        'evidence_quote' => "She's also not a massive fan of pesto",
    ]));

    expect($workspace['tahlia']->preferences()->where('subject', 'pesto')->count())->toBe(1);

    $unrelated = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'and 2 and 3',
        (string) Str::uuid(),
    );

    preferenceTool($workspace, $unrelated)->handle(new ToolRequest([
        'scope' => 'person',
        'person_id' => $workspace['tahlia']->id,
        'person_reference' => 'Tahlia',
        'subject' => 'sausages',
        'sentiment' => 'dislike',
        'provenance' => 'stated',
        'evidence_quote' => 'sausages',
    ]));
})->throws(ValidationException::class, 'Quote the exact words');

it('atomically supersedes a wrongly attributed preference and retains both human sources', function () {
    $workspace = truthReliabilityWorkspace();
    $incorrect = app(RecordPreference::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'fish',
        PreferenceSentiment::Dislike,
        PreferenceProvenance::Stated,
        $workspace['guest'],
        sourceMessage: $workspace['source'],
        evidenceQuote: "Tahlia doesn't like fish",
    );
    $correction = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'The fish preference was reported against Dinner guest erroneously. It should have been for Tahlia.',
        (string) Str::uuid(),
    );

    $corrected = app(CorrectPreference::class)->handle(
        $incorrect,
        $workspace['user'],
        $workspace['tahlia'],
        $correction,
        'The fish preference was reported against Dinner guest erroneously. It should have been for Tahlia.',
        'Tahlia',
    );

    expect($workspace['guest']->preferences()->count())->toBe(0)
        ->and($workspace['tahlia']->preferences()->where('subject', 'fish')->count())->toBe(1)
        ->and($incorrect->refresh()->superseded_by_id)->toBe($corrected->id)
        ->and($incorrect->superseded_at)->not->toBeNull()
        ->and($corrected->source_message_id)->toBe($workspace['source']->id)
        ->and($corrected->correction_message_id)->toBe($correction->id)
        ->and($corrected->evidence)->toBe([
            'message_id' => $workspace['source']->id,
            'correction_message_id' => $correction->id,
        ]);
});

it('replays the meal-plan-one person correction through the real agent tools', function () {
    $workspace = truthReliabilityWorkspace();
    $fish = app(RecordPreference::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'fish',
        PreferenceSentiment::Dislike,
        PreferenceProvenance::Stated,
        $workspace['guest'],
        sourceMessage: $workspace['source'],
        evidenceQuote: $workspace['source']->content,
    );
    $sausages = app(RecordPreference::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'sausages',
        PreferenceSentiment::Dislike,
        PreferenceProvenance::Stated,
        $workspace['guest'],
        sourceMessage: $workspace['source'],
        evidenceQuote: $workspace['source']->content,
    );
    $correction = 'The fish and sausages were reported against Dinner guest erroneously. It should have been for Tahlia.';
    ChefAgent::fake([
        new ToolCall('inspect-truth', 'InspectTeamContext', []),
        new ToolCall('correct-fish', 'CorrectHouseholdPreference', [
            'preference_id' => $fish->id,
            'corrected_person_id' => $workspace['tahlia']->id,
            'person_reference' => 'Tahlia',
            'evidence_quote' => $correction,
        ]),
        new ToolCall('correct-sausages', 'CorrectHouseholdPreference', [
            'preference_id' => $sausages->id,
            'corrected_person_id' => $workspace['tahlia']->id,
            'person_reference' => 'Tahlia',
            'evidence_quote' => $correction,
        ]),
        'I corrected both preferences. Only Tahlia now has them.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => $correction,
        'client_message_id' => (string) Str::uuid(),
    ]);

    $response->streamedContent();
    $assistant = $workspace['conversation']->messages()->reorder()->latest('id')->firstOrFail();

    expect($assistant->content)->toBe('I corrected both preferences. Only Tahlia now has them.')
        ->and($workspace['guest']->preferences()->count())->toBe(0)
        ->and($workspace['tahlia']->preferences()->whereIn('subject', ['fish', 'sausages'])->count())->toBe(2)
        ->and($fish->refresh()->superseded_at)->not->toBeNull()
        ->and($sausages->refresh()->superseded_at)->not->toBeNull();
});
