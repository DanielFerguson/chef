<?php

use App\Ai\Tools\RecoverableTool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

function reliabilityTool(string $outcome): Tool
{
    return new class($outcome) implements Tool
    {
        public function __construct(private readonly string $outcome) {}

        public function name(): string
        {
            return 'ReliabilityTool';
        }

        public function description(): string
        {
            return 'A test tool.';
        }

        public function handle(Request $request): string
        {
            return match ($this->outcome) {
                'validation' => throw ValidationException::withMessages(['meal_slot_id' => 'Choose a current meal slot.']),
                'missing' => throw (new ModelNotFoundException)->setModel(User::class, [99]),
                'authorization' => throw new AuthorizationException('Private household detail.'),
                'provider' => throw new RuntimeException('Provider failed.'),
                default => 'unchanged success result',
            };
        }

        public function schema(JsonSchema $schema): array
        {
            return ['title' => $schema->string()];
        }
    };
}

it('preserves tool identity, description, schema, and successful output', function () {
    $schema = new JsonSchemaTypeFactory;
    $tool = new RecoverableTool(reliabilityTool('success'));

    expect($tool->name())->toBe('ReliabilityTool')
        ->and((string) $tool->description())->toBe('A test tool.')
        ->and($tool->schema($schema)['title'])->toBeInstanceOf(StringType::class)
        ->and($tool->handle(new Request))->toBe('unchanged success result');
});

it('returns safe structured results for correctable tool input failures', function (string $outcome, string $code) {
    $result = json_decode((string) (new RecoverableTool(reliabilityTool($outcome)))->handle(new Request), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ok'])->toBeFalse()
        ->and($result['error']['code'])->toBe($code)
        ->and(json_encode($result))->not->toContain(User::class)->not->toContain('99');
})->with([
    'validation' => ['validation', 'validation_failed'],
    'missing resource' => ['missing', 'resource_not_found'],
]);

it('does not absorb authorization or unexpected system failures', function (string $outcome, string $exception) {
    expect(fn () => (new RecoverableTool(reliabilityTool($outcome)))->handle(new Request))
        ->toThrow($exception);
})->with([
    'authorization' => ['authorization', AuthorizationException::class],
    'provider' => ['provider', RuntimeException::class],
]);
