<?php

namespace App\Http\Controllers;

use App\Actions\Voice\EndVoiceSession;
use App\Actions\Voice\StartVoiceSession;
use App\Models\Conversation;
use App\Models\VoiceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class RealtimeSessionController extends Controller
{
    public function store(Request $request, Conversation $conversation, StartVoiceSession $startVoiceSession): JsonResponse
    {
        $this->authorize('update', $conversation);
        $validated = $request->validate([
            'sdp' => ['required', 'string', 'max:200000', 'starts_with:v=0'],
        ]);

        try {
            $result = $startVoiceSession->handle($conversation, $request->user(), $validated['sdp']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Voice is temporarily unavailable. You can keep typing to Chef.',
            ], 502);
        }

        return response()->json([
            'voice_session_id' => $result['session']->public_id,
            'sdp' => $result['answer_sdp'],
            'expires_at' => $result['session']->expires_at->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request, VoiceSession $voiceSession, EndVoiceSession $endVoiceSession): JsonResponse
    {
        $this->authorize('delete', $voiceSession);
        $endVoiceSession->handle($voiceSession, $request->user());

        return response()->json(['ended' => true]);
    }
}
