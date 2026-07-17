<?php

namespace App\Actions\Automation;

use App\Models\AutomationRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreAutomationScreenshot
{
    /** @return array{path: string, expires_at: CarbonInterface} */
    public function handle(AutomationRun $run, string $encoded): array
    {
        $encoded = preg_replace('/^data:image\/(?:png|jpeg);base64,/', '', trim($encoded)) ?? '';
        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['screenshot' => 'Provide a PNG or JPEG screenshot no larger than 5 MB.']);
        }

        $extension = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? 'png' : (str_starts_with($bytes, "\xff\xd8\xff") ? 'jpg' : null);

        if ($extension === null) {
            throw ValidationException::withMessages(['screenshot' => 'The browser screenshot must be PNG or JPEG data.']);
        }

        $path = 'automation/'.$run->uuid.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        return [
            'path' => $path,
            'expires_at' => now()->addHours((int) config('ai.computer_use.screenshot_retention_hours', 24)),
        ];
    }
}
