<?php

namespace App\Actions\Baskets;

use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Models\BasketRun;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryBasketRun
{
    public function handle(BasketRun $basketRun, User $user): BasketRun
    {
        if (! $user->can('retry', $basketRun)) {
            throw new AuthorizationException('Only the connected Coles account owner can retry this basket.');
        }
        $run = DB::transaction(function () use ($basketRun): BasketRun {
            $connection = $basketRun->retailer_connection_id === null
                ? null
                : RetailerConnection::query()->lockForUpdate()->find($basketRun->retailer_connection_id);
            $locked = BasketRun::query()
                ->with('groceryPlan.requirements')
                ->lockForUpdate()
                ->findOrFail($basketRun->id);

            if (! in_array($locked->status, [
                BasketRunStatus::Failed,
                BasketRunStatus::NeedsProduct,
            ], true) || $locked->basket_cleared_at !== null) {
                throw ValidationException::withMessages([
                    'basket' => 'This basket run cannot be retried safely. Review or restore it instead.',
                ]);
            }

            if ($connection?->active_session_id !== null || $connection?->active_session_claim_token !== null) {
                throw ValidationException::withMessages([
                    'basket' => 'Close Coles Live View before trying this basket again.',
                ]);
            }

            if ($connection === null || $connection->status !== RetailerConnectionStatus::Connected) {
                $locked->update([
                    'status' => BasketRunStatus::WaitingForConnection,
                    'failure_code' => null,
                    'failure_message' => null,
                ]);

                return $locked;
            }

            if ($locked->grocery_plan_id !== null) {
                foreach ($locked->groceryPlan->requirements as $requirement) {
                    $requirement->update([
                        'status' => GroceryRequirementStatus::Pending,
                    ]);
                    $requirement->candidates()->update([
                        'status' => RetailerCandidateStatus::Stale->value,
                    ]);
                }
                $locked->groceryPlan->update(['status' => GroceryPlanStatus::Ready]);
            }

            $locked->update([
                'status' => BasketRunStatus::DiscoveringProducts,
                'failure_code' => null,
                'failure_message' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return $locked;
        });

        if ($run->status === BasketRunStatus::DiscoveringProducts
            && config('retailer.features.discovery', false)) {
            DiscoverRetailerProductsJob::dispatch($run->id);
        }

        return $run->refresh();
    }
}
