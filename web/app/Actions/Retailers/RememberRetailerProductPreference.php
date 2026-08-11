<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerCandidateStatus;
use App\Models\BasketRun;
use App\Models\BasketRunItem;
use App\Models\Message;
use App\Models\RetailerProductCandidate;
use App\Models\RetailerProductPreference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RememberRetailerProductPreference
{
    public function handle(
        BasketRun $basketRun,
        BasketRunItem $item,
        RetailerProductCandidate $candidate,
        User $user,
        ?Message $sourceMessage = null,
    ): RetailerProductPreference {
        if (! $user->can('update', $basketRun->mealPlan)) {
            throw new AuthorizationException('You cannot save grocery preferences for this plan.');
        }
        if ($item->basket_run_id !== $basketRun->id
            || $item->team_id !== $basketRun->team_id
            || $candidate->team_id !== $basketRun->team_id
            || $candidate->grocery_requirement_id !== $item->grocery_requirement_id
            || $candidate->status !== RetailerCandidateStatus::Eligible) {
            throw ValidationException::withMessages([
                'retailer_product_candidate_id' => 'Choose a still-valid alternative discovered for this grocery item.',
            ]);
        }
        if ($sourceMessage !== null && $sourceMessage->team_id !== $basketRun->team_id) {
            throw new AuthorizationException('That preference source belongs to another household.');
        }

        $requirement = $item->requirement;

        return DB::transaction(function () use ($requirement, $candidate, $user, $sourceMessage): RetailerProductPreference {
            $preference = RetailerProductPreference::query()
                ->where('team_id', $requirement->team_id)
                ->where('provider', $candidate->provider)
                ->where('normalized_name', $requirement->normalized_name)
                ->when(
                    $requirement->normalized_form === null,
                    fn ($query) => $query->whereNull('normalized_form'),
                    fn ($query) => $query->where('normalized_form', $requirement->normalized_form),
                )
                ->whereNull('revoked_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $values = [
                'team_id' => $requirement->team_id,
                'created_by_user_id' => $user->id,
                'source_message_id' => $sourceMessage?->id,
                'provider' => $candidate->provider,
                'normalized_name' => $requirement->normalized_name,
                'normalized_form' => $requirement->normalized_form,
                'sku' => $candidate->sku,
                'product_title' => $candidate->title,
                'reason' => 'Preferred next time by a household member.',
                'revoked_at' => null,
            ];

            if ($preference === null) {
                return RetailerProductPreference::query()->create($values);
            }

            $preference->update($values);

            return $preference->refresh();
        });
    }
}
