<?php

namespace App\Actions\Conversations;

use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\ImageCodecCapabilities;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\Image as LaravelImage;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreateUserMessage
{
    public function __construct(
        private ImageCodecCapabilities $imageCodecCapabilities,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  list<UploadedFile>  $images
     */
    public function handle(
        Conversation $conversation,
        User $user,
        string $content,
        string $clientMessageId,
        ?array $metadata = null,
        array $images = [],
    ): Message {
        if (! $user->can('update', $conversation)) {
            throw new AuthorizationException('You cannot contribute to this conversation.');
        }

        $content = trim($content);
        $sourceHashes = array_map(
            fn (UploadedFile $image): string => $this->sourceHash($image),
            $images,
        );

        if (count($sourceHashes) !== count(array_unique($sourceHashes))) {
            throw ValidationException::withMessages([
                'images' => 'The same photo cannot be attached more than once.',
            ]);
        }

        $storedFiles = [];

        try {
            return DB::transaction(function () use ($conversation, $user, $content, $clientMessageId, $metadata, $images, $sourceHashes, &$storedFiles): Message {
                $message = Message::query()->firstOrCreate(
                    ['conversation_id' => $conversation->id, 'client_message_id' => $clientMessageId],
                    [
                        'team_id' => $conversation->team_id,
                        'user_id' => $user->id,
                        'role' => MessageRole::User,
                        'content' => $content,
                        'metadata' => $metadata,
                        'response_status' => MessageResponseStatus::Pending,
                    ],
                );

                if (
                    $message->user_id !== $user->id
                    || $message->content !== $content
                    || ($message->metadata['tool_approval'] ?? null) !== ($metadata['tool_approval'] ?? null)
                ) {
                    throw ValidationException::withMessages([
                        'client_message_id' => 'That client message identifier was already used for different content.',
                    ]);
                }

                if (! $message->wasRecentlyCreated) {
                    $existingHashes = $message->attachments()->pluck('source_sha256')->all();

                    if ($images !== [] && $existingHashes !== $sourceHashes) {
                        throw ValidationException::withMessages([
                            'client_message_id' => 'That client message identifier was already used with different photos.',
                        ]);
                    }

                    return $message->load('attachments');
                }

                foreach ($images as $position => $upload) {
                    $attachment = $this->storeImage(
                        $conversation,
                        $upload,
                        $sourceHashes[$position],
                        $position,
                    );
                    $storedFiles[] = [$attachment['disk'], $attachment['path']];
                    $message->attachments()->create($attachment);
                }

                return $message->load('attachments');
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * @return array{team_id: int, disk: string, path: string, mime_type: string, size_bytes: int, width: int, height: int, source_sha256: string, position: int}
     */
    private function storeImage(
        Conversation $conversation,
        UploadedFile $upload,
        string $sourceHash,
        int $position,
    ): array {
        $disk = (string) config('filesystems.default', 'local');
        $image = $this->normalizeImage($upload, $position);
        $contents = $image->toBytes();
        [$width, $height] = $image->dimensions();
        $directory = "conversation-images/{$conversation->team_id}/{$conversation->id}";
        $path = $image->storeAs(
            $directory,
            Str::uuid().'.jpg',
            $disk,
            ['visibility' => 'private'],
        );

        if ($path === false) {
            throw new RuntimeException('The photo could not be stored.');
        }

        return [
            'team_id' => $conversation->team_id,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $image->mimeType(),
            'size_bytes' => strlen($contents),
            'width' => $width,
            'height' => $height,
            'source_sha256' => $sourceHash,
            'position' => $position,
        ];
    }

    private function normalizeImage(UploadedFile $upload, int $position): LaravelImage
    {
        $mimeType = (string) $upload->getMimeType();

        if (! $this->isHeicOrHeif($mimeType)) {
            return Image::fromUpload($upload)
                ->usingGd()
                ->orient()
                ->scale(2560, 2560)
                ->optimize(format: 'jpg', quality: 82);
        }

        if (! $this->imageCodecCapabilities->supportsMimeType($mimeType)) {
            throw ValidationException::withMessages([
                "images.{$position}" => 'HEIC and HEIF photos are not supported by this server. Export the photo as JPEG and try again.',
            ]);
        }

        try {
            $pixels = Image::fromUpload($upload)
                ->usingImagick()
                ->orient()
                ->scale(2560, 2560)
                ->toPng()
                ->toBytes();
        } catch (ImageException $exception) {
            throw ValidationException::withMessages([
                "images.{$position}" => 'This HEIC or HEIF photo could not be processed. Export it as JPEG and try again.',
            ]);
        }

        return Image::fromBytes($pixels)
            ->usingGd()
            ->optimize(format: 'jpg', quality: 82);
    }

    private function isHeicOrHeif(string $mimeType): bool
    {
        return in_array($mimeType, [
            'image/heic',
            'image/heif',
            'image/x-heic',
            'image/x-heif',
        ], true);
    }

    private function sourceHash(UploadedFile $image): string
    {
        $hash = hash_file('sha256', $image->getRealPath());

        if ($hash === false) {
            throw new RuntimeException('The photo could not be read.');
        }

        return $hash;
    }
}
