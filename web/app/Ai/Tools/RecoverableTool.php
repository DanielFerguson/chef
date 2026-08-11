<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

class RecoverableTool implements Tool
{
    public function __construct(protected readonly Tool $tool) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            return $this->tool->handle($request);
        } catch (ValidationException $exception) {
            return json_encode([
                'ok' => false,
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The tool request was not applied. Correct the arguments using current structured state and try again.',
                    'fields' => $exception->errors(),
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (ModelNotFoundException) {
            return json_encode([
                'ok' => false,
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => 'The requested record is not available in the current structured state. Inspect again before retrying.',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }
}
