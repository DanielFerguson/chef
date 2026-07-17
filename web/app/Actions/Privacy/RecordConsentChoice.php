<?php

namespace App\Actions\Privacy;

use App\Models\ConsentRecord;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class RecordConsentChoice
{
    /**
     * @param  array<string, mixed>|null  $scope
     */
    public function handle(Team $team, User $user, string $kind, string $status, ?array $scope = null): ConsentRecord
    {
        if (! $user->teams()->whereKey($team->id)->exists()) {
            throw new AuthorizationException('You do not belong to this family.');
        }

        $purpose = match ($kind) {
            'product_analytics' => 'Help Chef improve through optional product-usage measurement.',
            'beta_research' => 'Allow the Chef team to use attributed beta feedback for product research.',
            default => throw ValidationException::withMessages(['kind' => 'This consent choice is not available.']),
        };

        if (! in_array($status, ['granted', 'declined', 'revoked'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose whether to allow or decline this use.']);
        }

        return ConsentRecord::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => $status,
            'purpose' => $purpose,
            'scope' => $scope,
            'occurred_at' => now(),
        ]);
    }
}
