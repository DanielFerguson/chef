<?php

use App\Actions\Automation\ExpireAutomationArtifacts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('automation:expire', function (ExpireAutomationArtifacts $expire) {
    $result = $expire->handle();
    $this->info("Expired {$result['runs']} runs, {$result['approvals']} approvals, {$result['connections']} connections, and {$result['screenshots']} screenshots; stopped {$result['stalled_steps']} stalled browser steps.");
})->purpose('Expire automation runs, approvals, browser connections, and retained screenshots');

Schedule::command('automation:expire')->everyMinute()->withoutOverlapping();
