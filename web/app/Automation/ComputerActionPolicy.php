<?php

namespace App\Automation;

use Illuminate\Validation\ValidationException;

class ComputerActionPolicy
{
    /** @var string[] */
    private const ALLOWED_TYPES = ['click', 'double_click', 'scroll', 'type', 'wait', 'keypress', 'drag', 'move', 'screenshot'];

    /** @param array<int, array<string, mixed>> $actions */
    public function assertAllowed(array $actions): void
    {
        if ($actions === [] || count($actions) > 25) {
            throw ValidationException::withMessages(['actions' => 'Computer calls must contain between one and 25 actions.']);
        }

        foreach ($actions as $index => $action) {
            $type = $action['type'] ?? null;

            if (! is_string($type) || ! in_array($type, self::ALLOWED_TYPES, true)) {
                throw ValidationException::withMessages(["actions.$index.type" => 'The computer action is not allowed.']);
            }

            if ($type === 'type' && mb_strlen((string) ($action['text'] ?? '')) > 2000) {
                throw ValidationException::withMessages(["actions.$index.text" => 'Typed text is too long.']);
            }
        }
    }
}
