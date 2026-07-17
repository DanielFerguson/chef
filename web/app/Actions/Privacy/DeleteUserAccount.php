<?php

namespace App\Actions\Privacy;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeleteUserAccount
{
    public function __construct(private readonly DeleteTeam $deleteTeam) {}

    public function ensureCanDelete(User $user): void
    {
        $ownedTeams = $user->memberships()
            ->where('role', TeamRole::Owner)
            ->with('team')
            ->get();

        $sharedOwnedTeam = $ownedTeams->first(fn ($membership) => $membership->team->memberships()->where('user_id', '!=', $user->id)->exists());

        if ($sharedOwnedTeam !== null) {
            throw ValidationException::withMessages([
                'account' => 'Transfer ownership or delete '.$sharedOwnedTeam->team->name.' before deleting your account.',
            ]);
        }
    }

    public function handle(User $user, bool $validated = false): void
    {
        if (! $validated) {
            $this->ensureCanDelete($user);
        }

        $ownedTeams = $user->memberships()
            ->where('role', TeamRole::Owner)
            ->with('team')
            ->get();

        foreach ($ownedTeams as $membership) {
            $this->deleteTeam->handle($membership->team, $user, ensureReplacement: false);
        }

        $user->delete();
    }
}
