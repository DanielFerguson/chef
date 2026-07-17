<?php

namespace App\Actions\Privacy;

use App\Actions\Teams\CreateTeamForUser;
use App\Models\AutomationStep;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteTeam
{
    public function __construct(private readonly CreateTeamForUser $createTeamForUser) {}

    public function handle(Team $team, User $user, bool $ensureReplacement = true): ?Team
    {
        if (! $user->can('delete', $team)) {
            throw new AuthorizationException('Only a family owner can delete this family.');
        }

        $disk = Storage::disk((string) config('chef.storage.automation_screenshots_disk'));
        $paths = AutomationStep::query()
            ->where('team_id', $team->id)
            ->whereNotNull('screenshot_path')
            ->pluck('screenshot_path');

        foreach ($paths as $path) {
            if ($disk->exists((string) $path) && ! $disk->delete((string) $path)) {
                throw ValidationException::withMessages(['team_name' => 'Chef could not remove all retained browser screenshots. Try again before deleting the family.']);
            }
        }

        return DB::transaction(function () use ($team, $user, $ensureReplacement): ?Team {
            $replacement = $user->teams()->whereKeyNot($team->id)->orderBy('teams.id')->first();
            $team->delete();

            if ($replacement !== null) {
                $user->forceFill(['current_team_id' => $replacement->id])->save();

                return $replacement;
            }

            if (! $ensureReplacement) {
                return null;
            }

            return $this->createTeamForUser->handle($user, 'New family');
        });
    }
}
