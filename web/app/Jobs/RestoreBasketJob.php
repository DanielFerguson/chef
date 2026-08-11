<?php

namespace App\Jobs;

use App\Actions\Baskets\RestoreBasket;
use App\Actions\Retailers\EnsureRetailerMutationIsEnabled;
use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

class RestoreBasketJob implements ShouldBeUnique, ShouldQueue
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
        return 'basket-restoration:'.$this->basketRunId;
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
        RestoreBasket $restoreBasket,
        EnsureRetailerMutationIsEnabled $ensureMutationIsEnabled,
        RetailerAutomationGateway $gateway,
    ): void {
        $run = BasketRun::query()->findOrFail($this->basketRunId);

        try {
            $ensureMutationIsEnabled->handle();
        } catch (RuntimeException) {
            $run->update([
                'status' => BasketRunStatus::NeedsAttention,
                'failure_code' => 'restoration_paused',
                'failure_message' => 'Basket restoration is paused. Review the Coles basket directly.',
            ]);

            return;
        }

        $restoreBasket->handle($run, $gateway);
    }
}
