<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\MealPlans\BuildMealPlanWorkspace;
use App\Actions\MealPlans\DeleteMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\LaravelAiConversationEngine;
use App\Enums\MessageRole;
use App\Jobs\DeletePendingMessageAttachmentFilesJob;
use App\Models\Conversation;
use App\Models\MealPlan;
use App\Models\PendingMessageAttachmentDeletion;
use App\Models\Team;
use App\Models\User;
use App\Support\ImageCodecCapabilities;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MockExpectation;

/**
 * @return array{user: User, team: Team, plan: MealPlan, conversation: Conversation}
 */
function attachmentWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Photo family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));

    return [
        'user' => $user,
        'team' => $team,
        'plan' => $plan,
        'conversation' => $plan->conversations()->firstOrFail(),
    ];
}

function attachmentExifOrientedPhoto(): UploadedFile
{
    $fixture = file_get_contents(base_path('tests/Fixtures/Images/oriented-with-exif.base64'));

    if ($fixture === false) {
        throw new RuntimeException('The EXIF image fixture could not be read.');
    }

    $contents = base64_decode(trim($fixture), true);

    if ($contents === false) {
        throw new RuntimeException('The EXIF image fixture could not be decoded.');
    }

    return UploadedFile::fake()->createWithContent('oriented-fridge.jpg', $contents);
}

function attachmentTransparentPng(): UploadedFile
{
    $image = imagecreatetruecolor(20, 20);

    if ($image === false) {
        throw new RuntimeException('The transparent PNG fixture could not be created.');
    }

    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);

    if ($transparent === false) {
        throw new RuntimeException('The transparent PNG color could not be created.');
    }

    imagefill($image, 0, 0, $transparent);
    ob_start();
    imagepng($image);
    $contents = ob_get_clean();
    imagedestroy($image);

    if (! is_string($contents)) {
        throw new RuntimeException('The transparent PNG fixture could not be encoded.');
    }

    return UploadedFile::fake()->createWithContent('transparent-pantry.png', $contents);
}

function attachmentHeicPhoto(string $fixture, string $name): UploadedFile
{
    $contents = file_get_contents(base_path("tests/Fixtures/Images/{$fixture}"));

    if ($contents === false) {
        throw new RuntimeException('The HEIC image fixture could not be read.');
    }

    return UploadedFile::fake()->createWithContent($name, $contents);
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

it('accepts text plus a photo and sends the normalized image to the streamed prompt', function () {
    $workspace = attachmentWorkspace();
    ChefAgent::fake(['I can help use what is in the fridge.'])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => 'Please use these ingredients plus rice.',
            'client_message_id' => (string) Str::uuid(),
            'images' => [UploadedFile::fake()->image('fridge.jpg', 3200, 1600)->size(900)],
        ],
    );

    $response->assertOk();
    expect($response->streamedContent())->toContain('fridge.');

    $message = $workspace['conversation']->messages()->where('role', MessageRole::User)->sole();
    $attachment = $message->attachments()->sole();

    Storage::disk('local')->assertExists($attachment->path);
    expect($attachment->mime_type)->toBe('image/jpeg')
        ->and(max($attachment->width, $attachment->height))->toBe(2560)
        ->and($attachment->path)->toEndWith('.jpg')
        ->and($attachment->path)->not->toContain('fridge')
        ->and($attachment->toArray())->not->toHaveKeys(['disk', 'path', 'source_sha256', 'team_id', 'message_id']);

    ChefAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1
        && $prompt->attachments->first() instanceof StoredImage);
});

it('accepts a photo-only turn and keeps the transcript content empty', function () {
    $workspace = attachmentWorkspace();
    ChefAgent::fake(['That looks like a useful starting point.'])->preventStrayPrompts();

    $this->actingAs($workspace['user'])->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => '',
            'client_message_id' => (string) Str::uuid(),
            'images' => [UploadedFile::fake()->image('pantry.png', 800, 600)],
        ],
    )->assertOk();

    $message = $workspace['conversation']->messages()->where('role', MessageRole::User)->sole();

    expect($message->content)->toBe('')
        ->and($message->attachments)->toHaveCount(1)
        ->and($message->attachments->sole()->width)->toBe(800)
        ->and($message->attachments->sole()->height)->toBe(600);

});

it('accepts an oriented HEIC photo and normalizes it as a private JPEG', function () {
    $workspace = attachmentWorkspace();
    $photo = attachmentHeicPhoto('iphone-fridge.heic', 'private-iphone-fridge.heic');
    $sourceHash = hash_file('sha256', $photo->getRealPath());
    ChefAgent::fake(['I can help use what is visible in the fridge.'])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => 'Please use what is in this photo.',
            'client_message_id' => (string) Str::uuid(),
            'images' => [$photo],
        ],
    );

    $response->assertOk();

    $message = $workspace['conversation']->messages()->where('role', MessageRole::User)->sole();
    $attachment = $message->attachments()->sole();
    $storedPath = Storage::disk('local')->path($attachment->path);
    $storedMetadata = exif_read_data($storedPath);

    expect($attachment->mime_type)->toBe('image/jpeg')
        ->and([$attachment->width, $attachment->height])->toBe([1280, 2560])
        ->and($attachment->source_sha256)->toBe($sourceHash)
        ->and($attachment->path)->toEndWith('.jpg')
        ->and($attachment->path)->not->toContain('private-iphone-fridge')
        ->and(is_array($storedMetadata) ? $storedMetadata : [])->not->toHaveKeys([
            'Make',
            'Model',
            'GPSLatitude',
            'GPSLongitude',
            'Orientation',
        ]);
    Storage::disk('local')->assertExists($attachment->path);
});

it('accepts a HEIF extension without enlarging the source image', function () {
    $workspace = attachmentWorkspace();
    ChefAgent::fake(['That pantry photo is ready.'])->preventStrayPrompts();

    $this->actingAs($workspace['user'])->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => '',
            'client_message_id' => (string) Str::uuid(),
            'images' => [attachmentHeicPhoto('iphone-pantry.heif', 'iphone-pantry.heif')],
        ],
    )->assertOk();

    $attachment = $workspace['conversation']
        ->messages()
        ->where('role', MessageRole::User)
        ->sole()
        ->attachments()
        ->sole();

    expect([$attachment->width, $attachment->height])->toBe([80, 60])
        ->and($attachment->mime_type)->toBe('image/jpeg');
});

it('returns a field validation error when the HEIC codec is unavailable', function () {
    $workspace = attachmentWorkspace();
    $capabilities = Mockery::mock(ImageCodecCapabilities::class);
    MockExpectation::for($capabilities, 'supportsMimeType')
        ->once()
        ->with('image/heic')
        ->andReturn(false);
    app()->instance(ImageCodecCapabilities::class, $capabilities);

    $clientMessageId = (string) Str::uuid();
    $this->actingAs($workspace['user'])->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => '',
            'client_message_id' => $clientMessageId,
            'images' => [attachmentHeicPhoto('iphone-fridge.heic', 'fridge.heic')],
        ],
    )->assertInvalid('images.0');

    expect($workspace['conversation']->messages()->where('client_message_id', $clientMessageId)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects corrupt HEIC content as a field validation error', function () {
    $workspace = attachmentWorkspace();

    $this->actingAs($workspace['user'])->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => '',
            'client_message_id' => (string) Str::uuid(),
            'images' => [UploadedFile::fake()->createWithContent('broken.heic', 'not an image')],
        ],
    )->assertInvalid('images.0');
});

it('reuses the original HEIC source hash on idempotent retries', function () {
    $workspace = attachmentWorkspace();
    $clientMessageId = (string) Str::uuid();
    $action = app(CreateUserMessage::class);
    $photo = attachmentHeicPhoto('iphone-fridge.heic', 'fridge.heic');

    $original = $action->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Use this fridge photo.',
        $clientMessageId,
        images: [$photo],
    );
    $retry = $action->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Use this fridge photo.',
        $clientMessageId,
        images: [attachmentHeicPhoto('iphone-fridge.heic', 'renamed.heic')],
    );

    expect($retry->is($original))->toBeTrue()
        ->and($original->attachments()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('auto-orients images and removes source EXIF metadata', function () {
    $workspace = attachmentWorkspace();
    $photo = attachmentExifOrientedPhoto();
    $sourceMetadata = exif_read_data($photo->getRealPath());

    if (! is_array($sourceMetadata)) {
        throw new RuntimeException('Expected the source fixture to contain EXIF metadata.');
    }

    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        (string) Str::uuid(),
        images: [$photo],
    );
    $attachment = $message->attachments()->sole();
    $storedMetadata = exif_read_data(Storage::disk('local')->path($attachment->path));

    expect($sourceMetadata)->toMatchArray([
        'Make' => 'Chef Test Camera',
        'Model' => 'Private Fridge Cam',
        'Orientation' => 6,
    ])->and([$attachment->width, $attachment->height])->toBe([20, 40])
        ->and(is_array($storedMetadata) ? $storedMetadata : [])->not->toHaveKeys(['Make', 'Model', 'Orientation']);
});

it('converts transparent PNG and WebP inputs to white-backed JPEG images', function () {
    $workspace = attachmentWorkspace();
    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        (string) Str::uuid(),
        images: [
            attachmentTransparentPng(),
            UploadedFile::fake()->image('shelf.webp', 120, 80),
        ],
    );
    $attachments = $message->attachments()->get();

    foreach ($attachments as $attachment) {
        $contents = Storage::disk('local')->get($attachment->path);
        $details = getimagesizefromstring($contents);

        if ($details === false) {
            throw new RuntimeException('The normalized JPEG metadata could not be inspected.');
        }

        expect($attachment->mime_type)->toBe('image/jpeg')
            ->and($attachment->path)->toEndWith('.jpg')
            ->and($details['mime'])->toBe('image/jpeg');
    }

    $transparentJpeg = imagecreatefromstring(
        Storage::disk('local')->get($attachments->firstOrFail()->path),
    );

    if ($transparentJpeg === false) {
        throw new RuntimeException('The normalized transparent PNG could not be decoded.');
    }

    $background = imagecolorat($transparentJpeg, 0, 0);
    imagedestroy($transparentJpeg);

    if ($background === false) {
        throw new RuntimeException('The normalized transparent PNG background could not be inspected.');
    }

    expect(($background >> 16) & 0xFF)->toBeGreaterThanOrEqual(250)
        ->and(($background >> 8) & 0xFF)->toBeGreaterThanOrEqual(250)
        ->and($background & 0xFF)->toBeGreaterThanOrEqual(250);
});

it('validates image count type size and the empty request', function () {
    $workspace = attachmentWorkspace();
    $route = route('conversations.messages.stream', $workspace['conversation']);

    $this->actingAs($workspace['user'])->post($route, [
        'content' => '',
        'client_message_id' => (string) Str::uuid(),
    ])->assertInvalid('content');

    $this->actingAs($workspace['user'])->post($route, [
        'content' => '',
        'client_message_id' => (string) Str::uuid(),
        'images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
    ])->assertInvalid('images.0');

    $this->actingAs($workspace['user'])->post($route, [
        'content' => '',
        'client_message_id' => (string) Str::uuid(),
        'images' => [UploadedFile::fake()->image('large.jpg')->size(10 * 1024 + 1)],
    ])->assertInvalid('images.0');

    $this->actingAs($workspace['user'])->post($route, [
        'content' => '',
        'client_message_id' => (string) Str::uuid(),
        'images' => collect(range(1, 5))->map(
            fn (int $number) => UploadedFile::fake()->image("photo-{$number}.jpg"),
        )->all(),
    ])->assertInvalid('images');
});

it('reuses stored photos on retry and rejects idempotency collisions', function () {
    $workspace = attachmentWorkspace();
    $clientMessageId = (string) Str::uuid();
    $photo = UploadedFile::fake()->image('fridge.jpg', 600, 400);
    $action = app(CreateUserMessage::class);

    $original = $action->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Use this photo.',
        $clientMessageId,
        images: [$photo],
    );
    $retry = $action->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Use this photo.',
        $clientMessageId,
    );

    expect($retry->is($original))->toBeTrue()
        ->and($original->attachments()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

    expect(fn () => $action->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Different text.',
        $clientMessageId,
    ))->toThrow(ValidationException::class);

    expect(fn () => $action->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Use this photo.',
        $clientMessageId,
        images: [UploadedFile::fake()->image('different.jpg', 700, 400)],
    ))->toThrow(ValidationException::class);
});

it('serializes only public attachment metadata in the workspace', function () {
    $workspace = attachmentWorkspace();
    app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Here is the fridge.',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->image('fridge.webp', 640, 480)],
    );

    $payload = app(BuildMealPlanWorkspace::class)->handle(
        $workspace['plan'],
        $workspace['user'],
    );
    $serialized = $payload['conversation']->messages
        ->firstWhere('role', MessageRole::User)
        ->toArray()['attachments'][0];

    expect($serialized)->toHaveKeys(['id', 'mime_type', 'size_bytes', 'width', 'height', 'position'])
        ->not->toHaveKeys(['team_id', 'message_id', 'disk', 'path', 'source_sha256']);
});

it('delivers private images to household members and returns 404 across households', function () {
    $workspace = attachmentWorkspace();
    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->image('fridge.jpg')],
    );
    $attachment = $message->attachments()->sole();

    $this->actingAs($workspace['user'])
        ->get(route('message-attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('content-disposition', 'inline; filename=photo-1.jpg')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('cache-control', 'max-age=86400, private');

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $this->actingAs($outsider)
        ->get(route('message-attachments.show', $attachment))
        ->assertNotFound();

    $this->actingAs($outsider)->post(
        route('conversations.messages.stream', $workspace['conversation']),
        [
            'content' => '',
            'client_message_id' => (string) Str::uuid(),
            'images' => [UploadedFile::fake()->image('other.jpg')],
        ],
    )->assertNotFound();

    Sanctum::actingAs($workspace['user']);
    $this->get("/api/v1/message-attachments/{$attachment->id}")
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
});

it('keeps existing WebP attachments readable', function () {
    $workspace = attachmentWorkspace();
    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->image('fridge.jpg')],
    );
    $attachment = $message->attachments()->sole();
    $legacyPath = dirname($attachment->path).'/legacy.webp';
    Storage::disk('local')->put($legacyPath, 'legacy-webp-contents', ['visibility' => 'private']);
    Storage::disk('local')->delete($attachment->path);
    $attachment->update([
        'path' => $legacyPath,
        'mime_type' => 'image/webp',
        'size_bytes' => strlen('legacy-webp-contents'),
    ]);

    $this->actingAs($workspace['user'])
        ->get(route('message-attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('content-type', 'image/webp')
        ->assertHeader('content-disposition', 'inline; filename=photo-1.webp');
});

it('retains photo context in later durable conversation turns', function () {
    $workspace = attachmentWorkspace();
    app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->image('fridge.jpg')],
    );
    $current = $workspace['conversation']->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'What could I cook with it?',
    ]);

    $prior = collect((new ChefAgent(
        $workspace['conversation'],
        $current->id,
        $workspace['user'],
        $current,
    ))->messages())->first(fn ($message) => $message instanceof UserMessage);

    if (! $prior instanceof UserMessage) {
        throw new RuntimeException('Expected the prior user message to retain its photo.');
    }

    expect($prior->attachments)->toHaveCount(1)
        ->and($prior->attachments->first())->toBeInstanceOf(StoredImage::class);
});

it('passes current photos to non-streamed prompts', function () {
    $workspace = attachmentWorkspace();
    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'What can I make?',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->image('fridge.jpg')],
    );
    ChefAgent::fake(['Try a vegetable frittata.'])->preventStrayPrompts();

    $reply = app(LaravelAiConversationEngine::class)->respondTo(
        $workspace['conversation'],
        $message,
    );

    expect($reply->content)->toBe('Try a vegetable frittata.');
    ChefAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1);
});

it('rolls back the message and leaves no file when image processing fails', function () {
    $workspace = attachmentWorkspace();
    $clientMessageId = (string) Str::uuid();

    expect(fn () => app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        $clientMessageId,
        images: [UploadedFile::fake()->createWithContent('broken.jpg', 'not an image')],
    ))->toThrow(ImageException::class);

    expect($workspace['conversation']->messages()->where('client_message_id', $clientMessageId)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('removes private photo files when the meal plan conversation is deleted', function () {
    $workspace = attachmentWorkspace();
    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        '',
        (string) Str::uuid(),
        images: [UploadedFile::fake()->image('fridge.jpg')],
    );
    $path = $message->attachments()->sole()->path;

    app(DeleteMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    Storage::disk('local')->assertMissing($path);
    $this->assertDatabaseMissing('message_attachments', ['message_id' => $message->id]);
    expect(PendingMessageAttachmentDeletion::query()->count())->toBe(0);
});

it('retains durable cleanup work when private photo deletion fails', function () {
    $deletion = PendingMessageAttachmentDeletion::factory()->create([
        'disk' => 'local',
        'path' => 'conversation-images/pending/photo.jpg',
    ]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    MockExpectation::for($disk, 'delete')
        ->once()
        ->with($deletion->path)
        ->andReturn(false);
    Storage::shouldReceive('disk')
        ->once()
        ->with($deletion->disk)
        ->andReturn($disk);

    expect(fn () => app()->call([new DeletePendingMessageAttachmentFilesJob, 'handle']))
        ->toThrow(RuntimeException::class, 'Private attachment cleanup remains pending.')
        ->and($deletion->refresh()->path)->toBe('conversation-images/pending/photo.jpg');
});
