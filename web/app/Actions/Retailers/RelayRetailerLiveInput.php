<?php

namespace App\Actions\Retailers;

use App\Models\RetailerConnection;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class RelayRetailerLiveInput
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'Enter',
        'Tab',
        'Escape',
        'Backspace',
        'Delete',
        'ArrowUp',
        'ArrowDown',
        'ArrowLeft',
        'ArrowRight',
        'Home',
        'End',
        'PageUp',
        'PageDown',
        'Space',
    ];

    public function handle(
        RetailerConnection $connection,
        User $user,
        RetailerAutomationGateway $gateway,
        #[\SensitiveParameter] ?string $text,
        ?string $key,
    ): void {
        if (! $user->can('useLiveView', $connection)) {
            throw new AuthorizationException('Only the connected Coles account owner can enter Live View input.');
        }

        if (($text === null) === ($key === null)) {
            throw ValidationException::withMessages([
                'input' => 'Send either text or one supported key.',
            ]);
        }

        if ($text !== null && (
            $text === ''
            || mb_strlen($text) > 256
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1
        )) {
            throw ValidationException::withMessages([
                'input' => 'The Live View text was not accepted.',
            ]);
        }

        if ($key !== null && ! in_array($key, self::ALLOWED_KEYS, true)) {
            throw ValidationException::withMessages([
                'input' => 'That Live View key is not supported.',
            ]);
        }

        if (
            $connection->browserbase_context_id === null
            || $connection->active_session_id === null
            || $connection->active_session_expires_at === null
            || $connection->active_session_expires_at->isPast()
            || ! in_array($connection->active_session_purpose, ['authentication', 'review'], true)
        ) {
            throw ValidationException::withMessages([
                'connection' => 'Open a new Coles Live View before entering text.',
            ]);
        }

        $result = $gateway->relayLiveInput(
            $connection->browserbase_context_id,
            $connection->active_session_id,
            $text,
            $key,
        );

        if (! $result->succeeded()) {
            throw ValidationException::withMessages([
                'connection' => 'The Coles Live View is no longer accepting input. Open a new session.',
            ]);
        }
    }
}
