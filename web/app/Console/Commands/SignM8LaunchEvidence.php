<?php

namespace App\Console\Commands;

use App\Support\M8LaunchEvidence;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class SignM8LaunchEvidence extends Command
{
    protected $signature = 'chef:release:evidence-sign {manifest : Path to an unsigned M8 evidence JSON file} {--output= : Write a signed copy to this path}';

    protected $description = 'Validate and sign content-free M8 launch evidence';

    public function handle(M8LaunchEvidence $evidence): int
    {
        try {
            $manifest = $this->readManifest((string) $this->argument('manifest'));
            $failures = $evidence->payloadFailures($manifest);

            if ($failures !== []) {
                $this->error('M8 evidence payload is not ready to sign.');

                foreach ($failures as $failure) {
                    $this->line(" - {$failure['key']}: {$failure['message']}");
                }

                return self::FAILURE;
            }

            $manifest['signature'] = $evidence->sign($manifest);
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
            $output = $this->option('output');

            if (is_string($output) && $output !== '') {
                if (file_put_contents($output, $json, LOCK_EX) === false) {
                    throw new \RuntimeException('Signed evidence could not be written.');
                }

                $this->info("Signed M8 evidence written to {$output}.");
            } else {
                $this->line($json);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException('Evidence manifest could not be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Evidence manifest could not be read.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Evidence manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest)) {
            throw new \RuntimeException('Evidence manifest must be a JSON object.');
        }

        return $manifest;
    }
}
