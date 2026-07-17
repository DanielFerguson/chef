<?php

namespace App\Http\Controllers;

use App\Actions\Voice\ExecuteVoiceConversationTurn;
use App\Models\VoiceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RealtimeToolCallController extends Controller
{
    public function __invoke(
        Request $request,
        VoiceSession $voiceSession,
        ExecuteVoiceConversationTurn $executeTurn,
    ): JsonResponse {
        $this->authorize('update', $voiceSession);
        $validated = $request->validate([
            'provider_call_id' => ['required', 'string', 'max:255'],
            'tool_name' => ['required', 'string', Rule::in([ExecuteVoiceConversationTurn::TOOL_NAME])],
            'arguments' => ['required', 'array:message'],
            'arguments.message' => ['required', 'string', 'max:10000'],
        ]);
        $call = $executeTurn->handle(
            $voiceSession,
            $request->user(),
            $validated['provider_call_id'],
            $validated['tool_name'],
            $validated['arguments'],
        );

        return response()->json([
            'tool_call_id' => $call->id,
            ...$call->result,
        ]);
    }
}
