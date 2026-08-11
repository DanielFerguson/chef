<?php

namespace App\Jobs;

use App\Actions\Baskets\ReplaceBasket;
use App\Actions\Retailers\EnsureRetailerMutationIsEnabled;
use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

class ReplaceBasketJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 360;

    public function __construct(public readonly int $basketRunId)
    {
        $this->onQueue('retailer');
    }

    public function uniqueId(): string
    {
        return 'basket-replacement:'.$this->basketRunId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $connectionId = BasketRun::query()
            ->whereKey($this->basketRunId)
            ->value('retailer_connection_id');

        return [
            (new WithoutOverlapping('retailer-connection:'.($connectionId ?? 'run-'.$this->basketRunId)))
                ->shared()
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(
        ReplaceBasket $replaceBasket,
        EnsureRetailerMutationIsEnabled $ensureMutationIsEnabled,
        RetailerAutomationGateway $gateway,
    ): void {
        $run = BasketRun::query()->findOrFail($this->basketRunId);

        try {
            $ensureMutationIsEnabled->handle();
        } catch (RuntimeException) {
            $run->update([
                'status' => BasketRunStatus::Failed,
                'failure_code' => 'mutation_disabled',
                'failure_message' => 'Coles basket changes are temporarily paused.',
            ]);

            return;
        }

        $replaceBasket->handle($run, $gateway);
    }
}
