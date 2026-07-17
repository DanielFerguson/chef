<?php

namespace App\Automation;

use Illuminate\Validation\ValidationException;

class ComputerActionPolicy
{
    /** @var string[] */
    private const ALLOWED_TYPES = ['click', 'double_click', 'scroll', 'type', 'wait', 'keypress', 'drag', 'move', 'screenshot'];

    /** @param array<int, mixed> $actions */
    public function assertAllowed(array $actions): void
    {
        if ($actions === [] || count($actions) > 25) {
            throw ValidationException::withMessages(['actions' => 'Computer calls must contain between one and 25 actions.']);
        }

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                throw ValidationException::withMessages(["actions.$index" => 'Each computer action must be an object.']);
            }

            $type = $action['type'] ?? null;

            if (! is_string($type) || ! in_array($type, self::ALLOWED_TYPES, true)) {
                throw ValidationException::withMessages(["actions.$index.type" => 'The computer action is not allowed.']);
            }

            match ($type) {
                'click', 'double_click', 'move' => $this->assertPoint($action, "actions.$index"),
                'scroll' => $this->assertScroll($action, "actions.$index"),
                'type' => $this->assertType($action, "actions.$index"),
                'wait' => $this->assertWait($action, "actions.$index"),
                'keypress' => $this->assertKeys($action, "actions.$index"),
                'drag' => $this->assertDrag($action, "actions.$index"),
                'screenshot' => null,
            };
        }
    }

    /** @param array<string, mixed> $action */
    private function assertPoint(array $action, string $path): void
    {
        $this->assertNumber($action['x'] ?? null, "$path.x", 0);
        $this->assertNumber($action['y'] ?? null, "$path.y", 0);
    }

    /** @param array<string, mixed> $action */
    private function assertScroll(array $action, string $path): void
    {
        $this->assertNumber($action['scroll_x'] ?? null, "$path.scroll_x");
        $this->assertNumber($action['scroll_y'] ?? null, "$path.scroll_y");

        if (array_key_exists('x', $action) || array_key_exists('y', $action)) {
            $this->assertPoint($action, $path);
        }
    }

    /** @param array<string, mixed> $action */
    private function assertType(array $action, string $path): void
    {
        if (! is_string($action['text'] ?? null) || mb_strlen($action['text']) > 2000) {
            throw ValidationException::withMessages(["$path.text" => 'Typed text must be a string no longer than 2,000 characters.']);
        }

        if (array_key_exists('x', $action) || array_key_exists('y', $action)) {
            $this->assertPoint($action, $path);
        }
    }

    /** @param array<string, mixed> $action */
    private function assertWait(array $action, string $path): void
    {
        if (! array_key_exists('duration_ms', $action)) {
            return;
        }

        $this->assertNumber($action['duration_ms'], "$path.duration_ms", 0, 10_000);
    }

    /** @param array<string, mixed> $action */
    private function assertKeys(array $action, string $path): void
    {
        $keys = $action['keys'] ?? null;

        if (! is_array($keys) || $keys === [] || count($keys) > 10) {
            throw ValidationException::withMessages(["$path.keys" => 'Keypress actions must contain between one and ten keys.']);
        }

        foreach ($keys as $keyIndex => $key) {
            if (! is_string($key) || $key === '' || mb_strlen($key) > 50) {
                throw ValidationException::withMessages(["$path.keys.$keyIndex" => 'Each key must be a non-empty string no longer than 50 characters.']);
            }
        }
    }

    /** @param array<string, mixed> $action */
    private function assertDrag(array $action, string $path): void
    {
        $points = $action['path'] ?? null;

        if (! is_array($points) || count($points) < 2 || count($points) > 100) {
            throw ValidationException::withMessages(["$path.path" => 'Drag actions must contain between two and 100 points.']);
        }

        foreach ($points as $pointIndex => $point) {
            if (! is_array($point)) {
                throw ValidationException::withMessages(["$path.path.$pointIndex" => 'Each drag point must be an object.']);
            }

            $this->assertPoint($point, "$path.path.$pointIndex");
        }
    }

    private function assertNumber(mixed $value, string $path, float $minimum = -100_000, float $maximum = 100_000): void
    {
        if (! is_int($value) && ! is_float($value)) {
            throw ValidationException::withMessages([$path => 'The computer action value must be numeric.']);
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < $minimum || $number > $maximum) {
            throw ValidationException::withMessages([$path => 'The computer action value is outside the allowed range.']);
        }
    }
}
