<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('teams.{teamId}', fn ($user, int $teamId): bool => $user->memberships()->where('team_id', $teamId)->exists());
