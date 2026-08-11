<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentController extends Controller
{
    public function __invoke(Request $request, MessageAttachment $messageAttachment): StreamedResponse
    {
        $conversation = $messageAttachment->message()->with('conversation')->firstOrFail()->conversation;
        $this->authorize('view', $conversation);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($messageAttachment->disk);
        abort_unless($disk->exists($messageAttachment->path), 404);
        $extension = match ($messageAttachment->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $filename = 'photo-'.($messageAttachment->position + 1).'.'.$extension;

        return $disk->response(
            $messageAttachment->path,
            $filename,
            [
                'Content-Type' => $messageAttachment->mime_type,
                'Content-Disposition' => HeaderUtils::makeDisposition('inline', $filename),
                'Cache-Control' => 'private, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
