<?php

use App\Actions\Automation\ExpireAutomationArtifacts;
use App\Actions\Privacy\ExpireConversationContent;
use App\Actions\Privacy\ExpireOperationalEvents;
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

Artisan::command('privacy:expire-conversations', function (ExpireConversationContent $expire) {
    $messages = $expire->handle();
    $this->info("Expired content for {$messages} conversation messages.");
})->purpose('Redact conversation content older than the configured retention period');

Artisan::command('privacy:expire-operational-events', function (ExpireOperationalEvents $expire) {
    $events = $expire->handle();
    $this->info("Expired {$events} operational events.");
})->purpose('Delete operational metrics and audit events older than the configured retention period');

Schedule::command('automation:expire')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('chef:release:heartbeat')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('privacy:expire-conversations')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('privacy:expire-operational-events')->dailyAt('02:15')->withoutOverlapping()->onOneServer();
