<?php

namespace App\Voice;

use App\Models\VoiceSession;
use App\Voice\Contracts\RealtimeSessionBroker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiRealtimeSessionBroker implements RealtimeSessionBroker
{
    public function connect(VoiceSession $session, string $offerSdp): string
    {
        $apiKey = config('services.openai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI Realtime is not configured.');
        }

        $response = $this->request($apiKey, $session)
            ->send('POST', config('services.openai.realtime.endpoint'), [
                'multipart' => [
                    [
                        'name' => 'sdp',
                        'contents' => $offerSdp,
                    ],
                    [
                        'name' => 'session',
                        'contents' => json_encode($this->sessionConfiguration($session), JSON_THROW_ON_ERROR),
                    ],
                ],
            ])
            ->throw();

        if (trim($response->body()) === '') {
            throw new RuntimeException('OpenAI Realtime returned an empty session answer.');
        }

        return $response->body();
    }

    private function request(string $apiKey, VoiceSession $session): PendingRequest
    {
        $appKey = (string) config('app.key');
        $safetyIdentifier = hash_hmac('sha256', (string) $session->user_id, $appKey);

        return Http::withToken($apiKey)
            ->accept('application/sdp')
            ->withHeaders(['OpenAI-Safety-Identifier' => $safetyIdentifier])
            ->timeout((int) config('services.openai.realtime.timeout', 30));
    }

    /** @return array<string, mixed> */
    private function sessionConfiguration(VoiceSession $session): array
    {
        $session->loadMissing('conversation.mealPlan', 'team');
        $mealPlan = $session->conversation->mealPlan;
        $planTitle = $mealPlan === null ? 'this household plan' : $mealPlan->title;

        return [
            'type' => 'realtime',
            'model' => $session->model,
            'output_modalities' => ['audio'],
            'instructions' => implode(' ', [
                "You are Chef's live voice transport for {$session->team->name} and {$planTitle}.",
                'For every complete user utterance, call continue_chef_conversation exactly once.',
                'Put the user\'s words as faithfully as possible in the message argument.',
                'Never answer or change household state yourself; Chef\'s server tool is authoritative.',
                'After the tool result, speak only its assistant_response naturally and concisely.',
            ]),
            'audio' => [
                'input' => [
                    'turn_detection' => ['type' => 'semantic_vad'],
                    'transcription' => [
                        'model' => config('services.openai.realtime.transcription_model'),
                    ],
                ],
                'output' => [
                    'voice' => $session->voice,
                ],
            ],
            'tools' => [[
                'type' => 'function',
                'name' => 'continue_chef_conversation',
                'description' => 'Send the complete spoken household request to Chef so its authorised domain tools can inspect or update the durable plan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'description' => 'A faithful transcript of the user\'s complete spoken request.',
                        ],
                    ],
                    'required' => ['message'],
                    'additionalProperties' => false,
                ],
            ]],
            'tool_choice' => 'required',
        ];
    }
}
