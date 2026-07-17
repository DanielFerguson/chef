<?php

namespace App\Actions\Cooking;

use App\Enums\PreferenceCandidateStatus;
use App\Enums\PreferenceProvenance;
use App\Models\Preference;
use App\Models\PreferenceCandidate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPreferenceCandidate
{
    public function handle(PreferenceCandidate $candidate, User $user, PreferenceCandidateStatus $decision): PreferenceCandidate
    {
        if (! $user->can('update', $candidate)) {
            throw new AuthorizationException('You cannot review this preference candidate.');
        }

        if ($decision === PreferenceCandidateStatus::Pending) {
            throw ValidationException::withMessages(['decision' => 'Choose accept or dismiss.']);
        }

        return DB::transaction(function () use ($candidate, $user, $decision): PreferenceCandidate {
            $candidate = PreferenceCandidate::query()->lockForUpdate()->findOrFail($candidate->id);

            if ($candidate->status !== PreferenceCandidateStatus::Pending) {
                if ($candidate->status === $decision) {
                    return $candidate;
                }

                throw ValidationException::withMessages(['decision' => 'This preference candidate has already been reviewed.']);
            }

            $preference = null;

            if ($decision === PreferenceCandidateStatus::Accepted) {
                $preference = Preference::query()
                    ->active()
                    ->where('team_id', $candidate->team_id)
                    ->where('person_id', $candidate->person_id)
                    ->whereRaw('lower(subject) = ?', [$candidate->normalized_subject])
                    ->lockForUpdate()
                    ->first();

                if ($preference === null) {
                    $preference = Preference::query()->create([
                        'team_id' => $candidate->team_id,
                        'person_id' => $candidate->person_id,
                        'subject' => $candidate->subject,
                        'sentiment' => $candidate->sentiment,
                        'strength' => 4,
                        'provenance' => PreferenceProvenance::Feedback,
                        'confidence' => $candidate->confidence,
                        'evidence' => [
                            'preference_candidate_id' => $candidate->id,
                            ...$candidate->evidence,
                        ],
                        'idempotency_key' => hash('sha256', 'accepted-preference-candidate|'.$candidate->id),
                    ]);
                } elseif ($preference->sentiment !== $candidate->sentiment) {
                    throw ValidationException::withMessages([
                        'decision' => 'This person already has a conflicting preference. Edit that preference directly before accepting this candidate.',
                    ]);
                }
            }

            $candidate->update([
                'status' => $decision,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'preference_id' => $preference?->id,
            ]);

            return $candidate->refresh();
        });
    }
}
