<?php

namespace App\Actions\Retailer;

use App\Actions\Automation\CloseBrowserSession;
use App\Actions\Automation\CreateBrowserSession;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\ExistingCartDecision;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Models\BrowserSession;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Models\User;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\RetailerOrderAdvanceResult;

class AdvanceRetailerOrderRun
{
    public function __construct(
        private readonly RetailerBrowser $browser,
        private readonly CreateBrowserSession $createBrowserSession,
        private readonly CloseBrowserSession $closeBrowserSession,
        private readonly RecordPlacedRetailerOrder $recordPlacedRetailerOrder,
    ) {}

    public function handle(RetailerOrderRun $run): RetailerOrderAdvanceResult
    {
        $run = $run->fresh(['items', 'retailerConnection', 'shoppingList']);

        if ($run === null) {
            return new RetailerOrderAdvanceResult(false, 'missing');
        }

        if ($run->status->isTerminal() || $run->status->requiresHouseholdInput()) {
            return new RetailerOrderAdvanceResult(false, $run->status->value);
        }

        return match ($run->status) {
            RetailerOrderRunStatus::PreparingCart => $this->advanceCartPreparation($run),
            RetailerOrderRunStatus::CartReady,
            RetailerOrderRunStatus::FetchingFulfilmentOptions => $this->advanceFulfilmentOptions($run),
            RetailerOrderRunStatus::SubmittingOrder => $this->advanceSubmittingOrder($run),
            default => new RetailerOrderAdvanceResult(false, $run->status->value),
        };
    }

    private function advanceCartPreparation(RetailerOrderRun $run): RetailerOrderAdvanceResult
    {
        if ($run->expires_at?->isPast()) {
            $run->update([
                'status' => RetailerOrderRunStatus::Failed,
                'failure_message' => 'The cart preparation window expired.',
            ]);

            return new RetailerOrderAdvanceResult(false, 'expired');
        }

        $session = $this->ensureBrowserSession($run);
        $auth = $this->browser->probeAuth($session);

        if ($auth->botDetected || $auth->sensitiveScreen) {
            return $this->failForSafety($run, $auth);
        }

        if (! $auth->authenticated) {
            $run->retailerConnection->update([
                'status' => RetailerConnectionStatus::ReauthenticationRequired,
            ]);
            $run->update([
                'status' => RetailerOrderRunStatus::AwaitingReauthentication,
                'failure_message' => $auth->reason !== ''
                    ? $auth->reason
                    : 'Woolworths needs to be reconnected before cart preparation can continue.',
            ]);

            return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::AwaitingReauthentication->value);
        }

        $inspection = $this->browser->inspectCart($session);

        if ($inspection->botDetected || $inspection->sensitiveScreen) {
            return $this->failForSafety($run, null, $inspection);
        }

        if (! $inspection->isEmpty() && $run->existing_cart_decision === null) {
            $run->update([
                'status' => RetailerOrderRunStatus::AwaitingCartDecision,
                'failure_message' => null,
            ]);

            return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::AwaitingCartDecision->value);
        }

        if ($inspection->isEmpty() && $run->existing_cart_decision === null) {
            $run->update(['existing_cart_decision' => ExistingCartDecision::Merge]);
            $run = $run->refresh();
        }

        if ($run->existing_cart_decision === ExistingCartDecision::Replace) {
            $cleared = $this->browser->clearCart($session);

            if ($cleared->botDetected || $cleared->sensitiveScreen) {
                return $this->failForSafety($run, null, $cleared);
            }

            if (! $cleared->isEmpty()) {
                $run->update([
                    'status' => RetailerOrderRunStatus::Failed,
                    'failure_message' => 'Chef could not verify that the replaced cart is empty.',
                ]);

                return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::Failed->value);
            }

            $inspection = $cleared;
        }

        $budget = max(1, (int) config('automation.max_actions_per_job', 8));
        $workPerformed = 0;
        $currentCart = $inspection;

        while ($workPerformed < $budget) {
            $run->load('items');
            $item = $run->items->first(
                fn (RetailerOrderRunItem $candidate): bool => in_array($candidate->status, [
                    RetailerOrderRunItemStatus::Pending,
                    RetailerOrderRunItemStatus::Searching,
                ], true),
            );

            if ($item === null) {
                break;
            }

            $product = $this->productPayload($item);

            if ($product === null) {
                $item->update([
                    'status' => RetailerOrderRunItemStatus::Failed,
                    'failure_message' => 'This item has no frozen Woolworths product to add.',
                    'resolved_at' => now(),
                ]);

                return new RetailerOrderAdvanceResult(false, 'item_missing_product');
            }

            $item->update([
                'status' => RetailerOrderRunItemStatus::Searching,
                'attempts' => $item->attempts + 1,
                'failure_message' => null,
            ]);

            $added = $this->browser->addProduct($session, $product);
            $workPerformed++;
            $maxAttempts = $this->maxItemAttempts($run);

            if (! $added->ok) {
                $message = $added->errorMessage ?? 'Chef could not add this product to the cart.';

                if ($item->refresh()->attempts >= $maxAttempts) {
                    return $this->failItemAndRun(
                        $run,
                        $item,
                        $message,
                        'Chef could not add '.$product['name'].' to the cart.',
                    );
                }

                $item->update([
                    'status' => RetailerOrderRunItemStatus::Pending,
                    'failure_message' => $message,
                ]);

                return new RetailerOrderAdvanceResult(true, 'add_product_retry');
            }

            $currentCart = $this->browser->inspectCart($session);

            if ($currentCart->botDetected || $currentCart->sensitiveScreen) {
                return $this->failForSafety($run->refresh(), null, $currentCart);
            }

            if ($this->findMatchingLine($currentCart->lines, $product) === null) {
                $message = 'Added product was not visible in the cart yet.';

                if ($item->refresh()->attempts >= $maxAttempts) {
                    return $this->failItemAndRun(
                        $run,
                        $item,
                        $message,
                        'Chef could not verify a cart line for '.$product['name'].'.',
                    );
                }

                $item->update([
                    'status' => RetailerOrderRunItemStatus::Pending,
                    'failure_message' => $message,
                ]);

                return new RetailerOrderAdvanceResult(true, 'verify_cart_line_retry');
            }

            $item->update([
                'status' => RetailerOrderRunItemStatus::Matched,
                'matched_product' => [
                    'external_id' => $product['external_id'],
                    'product_name' => $product['name'],
                    'quantity' => $product['quantity'],
                ],
                'failure_message' => null,
                'resolved_at' => now(),
            ]);
        }

        $run->load('items');
        $unresolved = $run->items->contains(
            fn (RetailerOrderRunItem $item): bool => ! $item->status->isResolved(),
        );

        if ($unresolved) {
            return new RetailerOrderAdvanceResult(true, 'safe_item_checkpoint');
        }

        $run->update([
            'status' => RetailerOrderRunStatus::CartReady,
            'failure_message' => null,
            'cart_checksum' => hash('sha256', json_encode(
                $currentCart->lines,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )),
        ]);

        return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::CartReady->value);
    }

    private function advanceFulfilmentOptions(RetailerOrderRun $run): RetailerOrderAdvanceResult
    {
        if ($run->expires_at?->isPast()) {
            $run->update([
                'status' => RetailerOrderRunStatus::Failed,
                'failure_message' => 'The order run window expired before fulfilment options could be fetched.',
            ]);

            return new RetailerOrderAdvanceResult(false, 'expired');
        }

        $fulfilmentType = $run->fulfilment_type;
        $shoppingMethod = $run->shoppingList?->fulfilment_method;

        if ($fulfilmentType === null && in_array($shoppingMethod, ['delivery', 'pickup'], true)) {
            $fulfilmentType = $shoppingMethod;
        }

        if ($fulfilmentType === null) {
            return new RetailerOrderAdvanceResult(false, 'fulfilment_type_required');
        }

        $run->update([
            'status' => RetailerOrderRunStatus::FetchingFulfilmentOptions,
            'fulfilment_type' => $fulfilmentType,
            'selected_slot' => null,
            'failure_message' => null,
        ]);
        $run = $run->refresh();

        $session = $this->ensureBrowserSession($run);
        $options = $this->browser->extractFulfilmentOptions($session, $fulfilmentType);
        $type = $options->type !== '' ? $options->type : $fulfilmentType;
        $expiresAt = $options->expiresAt ?? now()->addMinutes(30);

        $run->update([
            'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
            'fulfilment_type' => $type,
            'fulfilment_options' => [
                'type' => $type,
                'slots' => $options->slots,
            ],
            'fulfilment_options_expires_at' => $expiresAt,
            'failure_message' => null,
        ]);

        return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::AwaitingFulfilmentSelection->value);
    }

    private function advanceSubmittingOrder(RetailerOrderRun $run): RetailerOrderAdvanceResult
    {
        $run = $run->fresh(['retailerConnection', 'items', 'shoppingList']);

        if ($run === null) {
            return new RetailerOrderAdvanceResult(false, 'missing');
        }

        // Never submit when placement is already claimed or awaiting household verification.
        if ($run->status->blocksResubmit() && $run->status !== RetailerOrderRunStatus::SubmittingOrder) {
            return new RetailerOrderAdvanceResult(false, $run->status->value);
        }

        if ($run->status !== RetailerOrderRunStatus::SubmittingOrder) {
            return new RetailerOrderAdvanceResult(false, $run->status->value);
        }

        if ($run->expires_at?->isPast()) {
            $run->update([
                'status' => RetailerOrderRunStatus::Failed,
                'failure_message' => 'The order run window expired before Chef could submit the Woolworths order.',
            ]);

            return new RetailerOrderAdvanceResult(false, 'expired');
        }

        $session = $this->ensureBrowserSession($run);
        $submitted = $this->browser->submitOrderWithDefaultPayment($session);

        if (! $submitted->ok) {
            $run->update([
                'status' => RetailerOrderRunStatus::Failed,
                'failure_message' => $submitted->errorMessage
                    ?? 'Chef could not submit the Woolworths order with the default card on file.',
            ]);

            return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::Failed->value);
        }

        $confirmation = $this->browser->extractOrderConfirmation($session);
        $reference = filled($confirmation->retailerOrderReference)
            ? trim((string) $confirmation->retailerOrderReference)
            : (filled($submitted->retailerOrderReference)
                ? trim((string) $submitted->retailerOrderReference)
                : null);
        $confirmationText = $confirmation->confirmationText
            ?? $submitted->confirmationText;

        if ($reference === null) {
            $run->update([
                'status' => RetailerOrderRunStatus::AwaitingPlacementVerification,
                'failure_message' => null,
                'confirmation' => [
                    ...(is_array($run->confirmation) ? $run->confirmation : []),
                    'submit' => [
                        'likely_placed' => true,
                        'confirmation_text' => $confirmationText,
                        'submitted_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

            return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::AwaitingPlacementVerification->value);
        }

        $user = $this->confirmingUser($run);

        $this->recordPlacedRetailerOrder->handle(
            $run,
            $user,
            retailerOrderReference: $reference,
            confirmationText: $confirmationText,
        );

        return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::Placed->value);
    }

    private function confirmingUser(RetailerOrderRun $run): User
    {
        $userId = is_array($run->confirmation) ? ($run->confirmation['user_id'] ?? null) : null;

        if (is_numeric($userId)) {
            $user = User::query()->find((int) $userId);

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->findOrFail($run->started_by_user_id);
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

    private function maxItemAttempts(RetailerOrderRun $run): int
    {
        return max(1, (int) ($run->limits['max_item_attempts'] ?? config('automation.max_item_attempts', 4)));
    }

    private function failItemAndRun(
        RetailerOrderRun $run,
        RetailerOrderRunItem $item,
        string $itemMessage,
        string $runMessage,
    ): RetailerOrderAdvanceResult {
        $item->update([
            'status' => RetailerOrderRunItemStatus::Failed,
            'failure_message' => $itemMessage,
            'resolved_at' => now(),
        ]);
        $run->update([
            'status' => RetailerOrderRunStatus::Failed,
            'failure_message' => $runMessage,
        ]);

        return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::Failed->value);
    }

    /**
     * @return array{external_id: string, name: string, quantity: float|int}|null
     */
    private function productPayload(RetailerOrderRunItem $item): ?array
    {
        $match = $item->requirement_snapshot['product_match'] ?? null;

        if (! is_array($match) || ! filled($match['external_id'] ?? null)) {
            return null;
        }

        $name = $match['product_name'] ?? $match['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $quantity = $match['quantity'] ?? $item->requirement_snapshot['quantity'] ?? 1;

        return [
            'external_id' => (string) $match['external_id'],
            'name' => $name,
            'quantity' => is_numeric($quantity) ? $quantity + 0 : 1,
        ];
    }

    /**
     * @param  array<int, mixed>  $lines
     * @param  array{external_id: string, name: string, quantity: float|int}  $product
     * @return array<string, mixed>|null
     */
    private function findMatchingLine(array $lines, array $product): ?array
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            if (isset($line['external_id']) && (string) $line['external_id'] === $product['external_id']) {
                return $line;
            }

            $lineName = (string) ($line['product_name'] ?? $line['name'] ?? '');

            if ($lineName !== '' && mb_strtolower(trim($lineName)) === mb_strtolower(trim($product['name']))) {
                return $line;
            }
        }

        return null;
    }

    private function failForSafety(
        RetailerOrderRun $run,
        ?AuthCheck $auth = null,
        ?CartInspection $inspection = null,
    ): RetailerOrderAdvanceResult {
        $message = 'Woolworths presented a safety pause.';

        if ($auth?->botDetected || $inspection?->botDetected) {
            $message = $auth?->reason !== null && $auth->reason !== ''
                ? $auth->reason
                : 'Woolworths presented bot detection.';
        } elseif ($auth?->sensitiveScreen || $inspection?->sensitiveScreen) {
            $message = $auth?->reason !== null && $auth->reason !== ''
                ? $auth->reason
                : 'Woolworths opened a sensitive page.';
        }

        $run->update([
            'status' => RetailerOrderRunStatus::Failed,
            'failure_message' => $message,
        ]);

        return new RetailerOrderAdvanceResult(false, RetailerOrderRunStatus::Failed->value);
    }
}
