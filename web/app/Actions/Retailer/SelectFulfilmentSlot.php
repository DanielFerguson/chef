<?php

namespace App\Actions\Retailer;

use App\Actions\Automation\CloseBrowserSession;
use App\Actions\Automation\CreateBrowserSession;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\BrowserSession;
use App\Models\RetailerOrderRun;
use App\Models\User;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\SlotSelection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SelectFulfilmentSlot
{
    public function __construct(
        private readonly RetailerBrowser $browser,
        private readonly CreateBrowserSession $createBrowserSession,
        private readonly CloseBrowserSession $closeBrowserSession,
    ) {}

    public function handle(
        RetailerOrderRun $run,
        User $user,
        string $slotId,
        string $fulfilmentType,
    ): RetailerOrderRun {
        if (! $user->can('selectFulfilment', $run)) {
            throw new AuthorizationException('You cannot choose fulfilment for this Woolworths order.');
        }

        if (! in_array($fulfilmentType, ['delivery', 'pickup'], true)) {
            throw ValidationException::withMessages([
                'fulfilment_type' => 'Choose delivery or pickup.',
            ]);
        }

        $run = $run->fresh(['retailerConnection']);

        if ($run === null || $run->status !== RetailerOrderRunStatus::AwaitingFulfilmentSelection) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'Fulfilment can only be chosen while Chef is waiting for a slot.',
            ]);
        }

        if ($run->fulfilment_options_expires_at === null || $run->fulfilment_options_expires_at->isPast()) {
            $run->update([
                'status' => RetailerOrderRunStatus::FetchingFulfilmentOptions,
                'selected_slot' => null,
                'failure_message' => null,
            ]);

            AdvanceRetailerOrderRunJob::dispatch($run->id)
                ->onQueue((string) config('automation.queue', 'automation'));

            throw ValidationException::withMessages([
                'slot_id' => 'Those fulfilment options have expired. Chef is fetching fresh slots.',
            ]);
        }

        $options = is_array($run->fulfilment_options) ? $run->fulfilment_options : [];
        $slots = is_array($options['slots'] ?? null) ? $options['slots'] : [];
        $snapshotType = is_string($options['type'] ?? null) ? $options['type'] : $run->fulfilment_type;

        if ($snapshotType !== null && $snapshotType !== $fulfilmentType) {
            throw ValidationException::withMessages([
                'fulfilment_type' => 'Choose a slot that matches the scraped fulfilment type.',
            ]);
        }

        $selected = null;

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            if ((string) ($slot['id'] ?? '') === $slotId) {
                $selected = $slot;
                break;
            }
        }

        if ($selected === null) {
            throw ValidationException::withMessages([
                'slot_id' => 'Choose a fulfilment slot from the current options.',
            ]);
        }

        $session = $this->ensureBrowserSession($run);
        $applied = $this->browser->applyFulfilmentSlot($session, new SlotSelection(
            id: (string) $selected['id'],
            label: (string) ($selected['label'] ?? $selected['id']),
            startsAt: isset($selected['starts_at']) && is_string($selected['starts_at']) ? $selected['starts_at'] : null,
            endsAt: isset($selected['ends_at']) && is_string($selected['ends_at']) ? $selected['ends_at'] : null,
            fee: isset($selected['fee']) && is_numeric($selected['fee']) ? (float) $selected['fee'] : null,
            fulfilmentType: $fulfilmentType,
        ));

        if (! $applied->ok) {
            throw ValidationException::withMessages([
                'slot_id' => $applied->errorMessage ?? 'Chef could not apply that fulfilment slot at Woolworths.',
            ]);
        }

        $run->update([
            'status' => RetailerOrderRunStatus::AwaitingOrderConfirmation,
            'fulfilment_type' => $fulfilmentType,
            'selected_slot' => $selected,
            'failure_message' => null,
        ]);

        return $run->refresh();
    }

    private function ensureBrowserSession(RetailerOrderRun $run): BrowserSession
    {
        $session = BrowserSession::query()
            ->where('retailer_connection_id', $run->retailer_connection_id)
            ->where('purpose', BrowserSessionPurpose::CartPreparation->value)
            ->whereIn('status', [
                BrowserSessionStatus::AgentControl->value,
                BrowserSessionStatus::Closing->value,
            ])
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        if ($session?->expires_at?->isPast()) {
            $session->update([
                'status' => BrowserSessionStatus::Expired,
                'ended_at' => now(),
            ]);
            $session = null;
        }

        if ($session?->status === BrowserSessionStatus::Closing) {
            $this->closeBrowserSession->handle($session);
            $session = null;
        }

        if ($session !== null) {
            return $session;
        }

        return $this->createBrowserSession->handle(
            $run->retailerConnection,
            BrowserSessionPurpose::CartPreparation,
        );
    }
}
