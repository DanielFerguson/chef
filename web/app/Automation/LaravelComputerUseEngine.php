<?php

namespace App\Automation;

use App\Actions\Automation\CloseBrowserSession;
use App\Actions\Automation\CreateAutomationIntervention;
use App\Actions\Automation\CreateBrowserSession;
use App\Actions\Automation\ReleaseRetailerConnectionLease;
use App\Actions\Automation\TransitionAutomationRun;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\ComputerUseClient;
use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Data\AutomationAdvanceResult;
use App\Automation\Data\CartInspection;
use App\Automation\Data\ItemPreparationResult;
use App\Automation\Data\WorkerCommand;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Automation\Policy\AutomationActionPolicy;
use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationPolicyDecision;
use App\Enums\AutomationRunItemStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\CartLineClassification;
use App\Enums\ExistingCartDecision;
use App\Enums\RetailerConnectionStatus;
use App\Models\AutomationRun;
use App\Models\AutomationRunItem;
use App\Models\AutomationStep;
use App\Models\BrowserSession;
use App\Models\CartSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaravelComputerUseEngine implements ComputerUseEngine
{
    public function __construct(
        private readonly RetailerCartAdapter $adapter,
        private readonly ComputerExecutor $executor,
        private readonly ComputerUseClient $computerUse,
        private readonly AutomationActionPolicy $actionPolicy,
        private readonly CreateBrowserSession $createSession,
        private readonly CloseBrowserSession $closeSession,
        private readonly ReleaseRetailerConnectionLease $releaseLease,
        private readonly TransitionAutomationRun $transition,
        private readonly CreateAutomationIntervention $createIntervention,
    ) {}

    public function advance(AutomationRun $run): AutomationAdvanceResult
    {
        try {
            return $this->advanceRun($run);
        } catch (RetailerContextRevokedException) {
            return $this->contextWasRevoked($run->refresh());
        } catch (BrowserSessionLostException) {
            return $this->sessionWasLost($run->refresh());
        }
    }

    private function advanceRun(AutomationRun $run): AutomationAdvanceResult
    {
        $advanceStartedAt = microtime(true);
        $run = AutomationRun::query()->with([
            'shoppingList',
            'retailerConnection.retailer',
            'items',
            'interventions',
        ])->findOrFail($run->id);

        if ($run->status->isTerminal()) {
            return new AutomationAdvanceResult(false, $run->status->value);
        }

        if (in_array($run->status, [
            AutomationRunStatus::AwaitingReauthentication,
            AutomationRunStatus::AwaitingExistingCartDecision,
            AutomationRunStatus::AwaitingItemDecision,
        ], true)) {
            $manualTakeoverActive = $run->status === AutomationRunStatus::AwaitingItemDecision
                && $run->interventions()
                    ->where('type', AutomationInterventionType::ManualTakeover->value)
                    ->where('status', AutomationInterventionStatus::Pending->value)
                    ->exists();

            if ($manualTakeoverActive) {
                return new AutomationAdvanceResult(false, 'manual_takeover');
            }

            $this->closeOpenSessions($run);

            return new AutomationAdvanceResult(false, $run->status->value);
        }

        if ($run->expires_at?->isPast()) {
            $this->closeOpenSessions($run);
            $this->transition->handle($run, AutomationRunStatus::Expired, [
                'failure_message' => 'The cart preparation window expired.',
                'finished_at' => now(),
            ]);

            return new AutomationAdvanceResult(false, 'expired');
        }

        if ($run->shoppingList->revision !== $run->shoppingListRevision->revision) {
            $this->closeOpenSessions($run);
            $this->transition->handle($run, AutomationRunStatus::Superseded, [
                'failure_message' => 'The shopping list changed after this cart run was approved.',
                'finished_at' => now(),
            ]);

            return new AutomationAdvanceResult(false, 'superseded');
        }

        $connection = $run->retailerConnection;
        if ($connection->status !== RetailerConnectionStatus::Connected) {
            return $this->awaitReauthentication($run, null, 'Woolworths needs to be reconnected before cart preparation can continue.');
        }

        $session = $this->activeAgentSession($run);
        if ($session === null) {
            $session = $this->createSession->handle($connection, BrowserSessionPurpose::CartPreparation, $run);
        }

        $authentication = $this->adapter->checkAuthentication($session);
        $this->recordStep($run, null, $session, 'authentication_probe', AutomationPolicyDecision::Allowed, [], [
            'authenticated' => $authentication->authenticated,
            'reason' => $authentication->reason,
            'bot_detected' => $authentication->botDetected,
            'sensitive_screen' => $authentication->sensitiveScreen,
        ]);

        if (! $authentication->authenticated || $authentication->botDetected || $authentication->sensitiveScreen) {
            return $this->awaitReauthentication($run->refresh(), $session, $authentication->reason);
        }

        if ($run->status === AutomationRunStatus::CheckingConnection) {
            $run = $this->transition->handle($run, AutomationRunStatus::InspectingExistingCart);
        }

        $inspection = $this->adapter->inspectCart($session);
        $this->recordCartInspection($run, $session, $inspection, 'inspect_cart');

        if ($inspection->botDetected || $inspection->sensitiveScreen) {
            return $this->pauseForSafety(
                $run->refresh(),
                $session,
                $inspection->botDetected ? AutomationInterventionType::BotDetection : AutomationInterventionType::SensitiveScreen,
                $inspection->botDetected ? 'Woolworths presented bot detection.' : 'Woolworths opened a sensitive page.',
            );
        }

        if (! $inspection->isEmpty() && $run->existing_cart_decision === null) {
            $this->createIntervention->handle(
                $run,
                AutomationInterventionType::ExistingCart,
                [
                    'message' => 'Woolworths already has items in this account’s cart.',
                    'choices' => array_map(
                        fn (ExistingCartDecision $decision) => $decision->value,
                        ExistingCartDecision::cases(),
                    ),
                    'lines' => array_map($this->sanitizeCartLine(...), $inspection->lines),
                    'cart_total' => $inspection->total,
                    'currency' => $inspection->currency,
                ],
                session: $session,
            );
            $this->transition->handle($run->refresh(), AutomationRunStatus::AwaitingExistingCartDecision);
            $this->closeOpenSessions($run);

            return new AutomationAdvanceResult(false, 'awaiting_existing_cart_decision');
        }

        if ($inspection->isEmpty() && $run->existing_cart_decision === null) {
            $run->update(['existing_cart_decision' => ExistingCartDecision::Merge]);
            $run = $this->transition->handle($run->refresh(), AutomationRunStatus::Queued);
        }

        if ($run->existing_cart_decision === ExistingCartDecision::Replace
            && ! $run->steps()->where('action_type', 'clear_existing_cart')->exists()) {
            $cleared = $this->adapter->clearCart($session);
            $this->recordCartInspection($run, $session, $cleared, 'clear_existing_cart');

            if (! $cleared->isEmpty()) {
                return $this->pauseForSafety(
                    $run->refresh(),
                    $session,
                    AutomationInterventionType::CartChanged,
                    'Chef could not verify that the explicitly replaced cart is empty.',
                );
            }
        }

        if ($run->status === AutomationRunStatus::InspectingExistingCart) {
            $run = $this->transition->handle($run, AutomationRunStatus::Queued);
        }

        if ($run->status === AutomationRunStatus::Queued) {
            $run = $this->transition->handle($run, AutomationRunStatus::Running);
        }

        $workBudget = max(1, (int) config('automation.max_actions_per_job', 8));
        $workPerformed = 0;
        $currentCart = $inspection;
        $preExistingLines = $run->existing_cart_decision === ExistingCartDecision::Merge
            ? $this->baselineLines($run)
            : [];

        while ($workPerformed < $workBudget) {
            if ((microtime(true) - $advanceStartedAt) >= (float) ($run->limits['max_runtime_seconds'] ?? config('automation.max_runtime_seconds', 180))) {
                return new AutomationAdvanceResult(true, 'safe_runtime_checkpoint');
            }

            $run->load('items');
            $item = $run->items->first(fn (AutomationRunItem $candidate) => in_array($candidate->status, [
                AutomationRunItemStatus::Pending,
                AutomationRunItemStatus::Searching,
            ], true));

            if ($item === null) {
                break;
            }

            if ($item->attempts >= (int) ($run->limits['max_item_attempts'] ?? config('automation.max_item_attempts', 4))) {
                return $this->pauseForItem(
                    $run,
                    $session,
                    $item,
                    AutomationInterventionType::ItemDecision,
                    'Chef could not verify this item within the retry limit.',
                );
            }

            $item->update([
                'status' => AutomationRunItemStatus::Searching,
                'attempts' => $item->attempts + 1,
                'failure_message' => null,
            ]);
            $result = $this->adapter->prepareItem(
                $session,
                $item->refresh(),
                $currentCart,
                $preExistingLines,
            );
            $workPerformed++;
            $this->recordStep($run, $item, $session, 'prepare_item', AutomationPolicyDecision::Allowed, [
                'requirement' => $this->requirementSummary($item),
            ], [
                'status' => $result->status->value,
                'product' => $this->sanitizeProduct($result->product),
                'requires_computer_use' => $result->requiresComputerUse,
            ]);

            $verifiedCart = $this->adapter->inspectCart($session);
            $currentCart = $verifiedCart;
            $this->recordCartInspection($run, $session, $verifiedCart, 'verify_item_mutation', $item);
            $outcome = $this->applyVerifiedOutcome($run, $item->refresh(), $result, $verifiedCart, $session);

            if ($outcome instanceof AutomationAdvanceResult) {
                return $outcome;
            }

            if ($outcome) {
                continue;
            }

            if ($run->actions_taken >= (int) ($run->limits['max_actions'] ?? config('automation.max_actions', 40))) {
                return $this->pauseForItem(
                    $run,
                    $session,
                    $item,
                    AutomationInterventionType::ItemDecision,
                    'Chef reached the safe browser-action limit before verifying this item.',
                );
            }

            $computerResult = $this->performComputerUseAction($run->refresh(), $item->refresh(), $session);
            if ($computerResult !== null) {
                return $computerResult;
            }

            $workPerformed++;
        }

        $run->load('items');
        $unresolved = $run->items->contains(fn (AutomationRunItem $item) => ! $item->status->isResolved());

        if ($unresolved) {
            return new AutomationAdvanceResult(true, 'safe_item_checkpoint');
        }

        $run = $this->transition->handle($run->refresh(), AutomationRunStatus::Reconciling);
        $finalCart = $this->adapter->reconcile($session);
        $this->recordCartInspection($run, $session, $finalCart, 'final_reconciliation');

        if ($finalCart->botDetected || $finalCart->sensitiveScreen) {
            return $this->pauseForSafety(
                $run->refresh(),
                $session,
                $finalCart->botDetected ? AutomationInterventionType::BotDetection : AutomationInterventionType::SensitiveScreen,
                'Chef could not safely finish the final cart reconciliation.',
            );
        }

        foreach ($run->items()
            ->whereIn('status', [
                AutomationRunItemStatus::Matched->value,
                AutomationRunItemStatus::Substituted->value,
            ])
            ->orderBy('position')
            ->get() as $verifiedItem) {
            if ($this->findRemoteLine($finalCart->lines, $verifiedItem->matched_product, $verifiedItem) !== null) {
                continue;
            }

            return $this->pauseForItem(
                $run->refresh(),
                $session,
                $verifiedItem,
                AutomationInterventionType::CartChanged,
                'A previously verified item is missing or changed in the Woolworths cart. Retry it, skip it, or cancel the run.',
                $verifiedItem->matched_product,
            );
        }

        $this->closeOpenSessions($run);
        $this->createSnapshot($run->refresh(), $finalCart);
        $this->transition->handle($run->refresh(), AutomationRunStatus::ReadyForReview, [
            'finished_at' => now(),
            'failure_message' => null,
        ]);

        return new AutomationAdvanceResult(false, 'ready_for_review');
    }

    private function activeAgentSession(AutomationRun $run): ?BrowserSession
    {
        $session = $run->browserSessions()
            ->whereIn('status', [BrowserSessionStatus::AgentControl->value, BrowserSessionStatus::Closing->value])
            ->latest()
            ->first();

        if ($session?->expires_at?->isPast()) {
            $session->update(['status' => BrowserSessionStatus::Expired, 'ended_at' => now()]);
            $this->releaseLease->handle($run->retailerConnection, 'session:'.$session->id);

            return null;
        }

        if ($session?->status === BrowserSessionStatus::Closing) {
            $this->closeSession->handle($session);

            return null;
        }

        return $session;
    }

    private function awaitReauthentication(
        AutomationRun $run,
        ?BrowserSession $session,
        string $reason,
        RetailerConnectionStatus $connectionStatus = RetailerConnectionStatus::ReauthenticationRequired,
    ): AutomationAdvanceResult {
        $run->retailerConnection->update(['status' => $connectionStatus]);
        $this->createIntervention->handle(
            $run,
            AutomationInterventionType::Reauthentication,
            ['message' => $reason],
            session: $session,
        );
        $this->transition->handle($run, AutomationRunStatus::AwaitingReauthentication);
        $this->closeOpenSessions($run);

        return new AutomationAdvanceResult(false, 'awaiting_reauthentication');
    }

    private function contextWasRevoked(AutomationRun $run): AutomationAdvanceResult
    {
        $this->expireLostSessions($run);
        $run->retailerConnection->update(['provider_context_id' => null]);
        $this->recordStep(
            $run,
            null,
            null,
            'browser_context_revoked',
            AutomationPolicyDecision::Blocked,
            [],
            ['requires_owner_reauthentication' => true],
        );

        return $this->awaitReauthentication(
            $run,
            null,
            'The saved Woolworths browser context was revoked. The connection owner must sign in again.',
            RetailerConnectionStatus::Revoked,
        );
    }

    private function sessionWasLost(AutomationRun $run): AutomationAdvanceResult
    {
        $this->expireLostSessions($run);
        $this->recordStep(
            $run,
            null,
            null,
            'browser_session_lost',
            AutomationPolicyDecision::Blocked,
            [],
            ['next_step' => 'fresh_authenticated_cart_inspection'],
        );

        return new AutomationAdvanceResult(true, 'browser_session_lost');
    }

    private function expireLostSessions(AutomationRun $run): void
    {
        $sessions = $run->browserSessions()
            ->whereNotIn('status', [BrowserSessionStatus::Closed->value, BrowserSessionStatus::Expired->value])
            ->get();

        foreach ($sessions as $session) {
            $session->update([
                'status' => BrowserSessionStatus::Expired,
                'ended_at' => now(),
            ]);
            $this->releaseLease->handle($run->retailerConnection, 'session:'.$session->id);
        }
    }

    private function pauseForSafety(
        AutomationRun $run,
        BrowserSession $session,
        AutomationInterventionType $type,
        string $message,
    ): AutomationAdvanceResult {
        $this->createIntervention->handle($run, $type, ['message' => $message], session: $session);
        $this->transition->handle($run, AutomationRunStatus::AwaitingItemDecision);
        $this->closeOpenSessions($run);

        return new AutomationAdvanceResult(false, $type->value);
    }

    /** @param array<string, mixed>|null $product */
    private function pauseForItem(
        AutomationRun $run,
        BrowserSession $session,
        AutomationRunItem $item,
        AutomationInterventionType $type,
        string $message,
        ?array $product = null,
    ): AutomationAdvanceResult {
        $item->update([
            'status' => AutomationRunItemStatus::AwaitingDecision,
            'failure_message' => $message,
        ]);
        $this->createIntervention->handle($run, $type, [
            'message' => $message,
            'requirement' => $this->requirementSummary($item),
            'product' => $this->sanitizeProduct($product),
        ], $item, $session);
        $this->transition->handle($run->refresh(), AutomationRunStatus::AwaitingItemDecision);
        $this->closeOpenSessions($run);

        return new AutomationAdvanceResult(false, 'awaiting_item_decision');
    }

    private function applyVerifiedOutcome(
        AutomationRun $run,
        AutomationRunItem $item,
        ItemPreparationResult $result,
        CartInspection $cart,
        BrowserSession $session,
    ): bool|AutomationAdvanceResult {
        if ($result->status === AutomationRunItemStatus::AwaitingDecision) {
            $type = Str::contains(Str::lower((string) $result->reason), 'price')
                ? AutomationInterventionType::PriceLimit
                : (Str::contains(Str::lower((string) $result->reason), 'substitut')
                    ? AutomationInterventionType::Substitution
                    : AutomationInterventionType::ItemDecision);

            return $this->pauseForItem(
                $run,
                $session,
                $item,
                $type,
                $result->reason ?? 'This item needs a decision before Chef can continue.',
                $result->product,
            );
        }

        if ($result->status === AutomationRunItemStatus::Unavailable) {
            $item->update([
                'status' => AutomationRunItemStatus::Unavailable,
                'failure_message' => $result->reason,
                'resolved_at' => now(),
            ]);

            return true;
        }

        if ($result->status === AutomationRunItemStatus::Skipped) {
            $item->update(['status' => AutomationRunItemStatus::Skipped, 'resolved_at' => now()]);

            return true;
        }

        if (! in_array($result->status, [AutomationRunItemStatus::Matched, AutomationRunItemStatus::Substituted], true)) {
            return false;
        }

        $verifiedLine = $this->findRemoteLine($cart->lines, $result->product, $item);
        if ($verifiedLine === null) {
            return false;
        }

        $maximumPrice = $item->requirement_snapshot['maximum_price'] ?? null;
        $observedPrice = $verifiedLine['total_price'] ?? $result->product['total_price'] ?? null;
        if (is_numeric($maximumPrice) && is_numeric($observedPrice) && (float) $observedPrice > (float) $maximumPrice) {
            return $this->pauseForItem(
                $run,
                $session,
                $item,
                AutomationInterventionType::PriceLimit,
                'The verified cart price is above this item’s maximum.',
                $verifiedLine,
            );
        }

        if ($result->status === AutomationRunItemStatus::Substituted
            && ! (bool) ($item->requirement_snapshot['accept_substitutes'] ?? false)) {
            return $this->pauseForItem(
                $run,
                $session,
                $item,
                AutomationInterventionType::Substitution,
                'This substitution is outside the saved product policy.',
                $verifiedLine,
            );
        }

        $item->update([
            'status' => $result->status,
            'matched_product' => $this->sanitizeProduct($verifiedLine),
            'failure_message' => null,
            'resolved_at' => now(),
        ]);

        return true;
    }

    private function performComputerUseAction(
        AutomationRun $run,
        AutomationRunItem $item,
        BrowserSession $session,
    ): ?AutomationAdvanceResult {
        $capture = $this->executor->execute($session, new WorkerCommand('capture'));

        if (! $capture->ok || ! is_string($capture->payload['screenshot'] ?? null)) {
            return $this->pauseForItem($run, $session, $item, AutomationInterventionType::ItemDecision, 'Chef could not obtain a safe browser observation.');
        }

        $lastStep = $run->steps()->whereNotNull('output_summary')->latest('sequence')->first();
        $previousOutput = $run->openai_response_id !== null && is_string($lastStep?->output_summary['call_id'] ?? null)
            ? ['call_id' => $lastStep->output_summary['call_id'], 'acknowledged_safety_checks' => []]
            : null;
        $turn = $this->computerUse->next($run, $item, $capture->payload['screenshot'], $previousOutput);
        $run->update(['openai_response_id' => $turn->responseId]);

        if ($turn->pendingSafetyChecks !== []) {
            return $this->pauseForSafety(
                $run->refresh(),
                $session,
                AutomationInterventionType::SensitiveScreen,
                'OpenAI requested a safety acknowledgement, so Chef stopped for a human decision.',
            );
        }

        if ($turn->complete || $turn->action === null || $turn->callId === null) {
            return $this->pauseForItem(
                $run->refresh(),
                $session,
                $item,
                AutomationInterventionType::ItemDecision,
                'Computer use stopped without a verified cart outcome.',
            );
        }

        $assessment = $this->actionPolicy->assess($turn->action, $capture->payload);
        if ($assessment->decision === AutomationPolicyDecision::RequiresIntervention) {
            return $this->pauseForSafety($run->refresh(), $session, AutomationInterventionType::SensitiveScreen, $assessment->reason);
        }

        if ($assessment->decision === AutomationPolicyDecision::Blocked) {
            return $this->pauseForItem($run->refresh(), $session, $item, AutomationInterventionType::ItemDecision, $assessment->reason);
        }

        $step = $this->recordStep(
            $run,
            $item,
            $session,
            (string) ($turn->action['type'] ?? 'computer_action'),
            $assessment->decision,
            [
                'action' => $this->sanitizeAction($turn->action),
                'call_id' => $turn->callId,
            ],
            [],
            complete: false,
        );
        $executed = $this->executor->execute($session, new WorkerCommand('execute_action', [
            'action' => $turn->action,
        ]));

        if (! $executed->ok) {
            $step->update([
                'output_summary' => [
                    'call_id' => $turn->callId,
                    'verified' => false,
                    'error_code' => $executed->errorCode,
                ],
                'completed_at' => now(),
            ]);

            return $this->pauseForItem(
                $run->refresh(),
                $session,
                $item,
                AutomationInterventionType::ItemDecision,
                $executed->errorMessage ?? 'The browser worker rejected an unsafe or unverifiable action.',
            );
        }

        $step->update([
            'output_summary' => [
                'call_id' => $turn->callId,
                'verified' => (bool) ($executed->payload['verified'] ?? false),
                'url_host' => $this->safeHost($executed->payload['url'] ?? null),
            ],
            'completed_at' => now(),
        ]);
        $run->increment('actions_taken');

        return null;
    }

    private function recordCartInspection(
        AutomationRun $run,
        BrowserSession $session,
        CartInspection $inspection,
        string $type,
        ?AutomationRunItem $item = null,
    ): void {
        $this->recordStep($run, $item, $session, $type, AutomationPolicyDecision::Allowed, [], [
            'line_count' => count($inspection->lines),
            'total' => $inspection->total,
            'currency' => $inspection->currency,
            'bot_detected' => $inspection->botDetected,
            'sensitive_screen' => $inspection->sensitiveScreen,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    private function recordStep(
        AutomationRun $run,
        ?AutomationRunItem $item,
        ?BrowserSession $session,
        string $type,
        AutomationPolicyDecision $decision,
        array $input,
        array $output,
        bool $complete = true,
    ): AutomationStep {
        return DB::transaction(function () use ($run, $item, $session, $type, $decision, $input, $output, $complete): AutomationStep {
            $locked = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);
            $sequence = $locked->current_sequence + 1;
            $locked->update(['current_sequence' => $sequence]);

            return AutomationStep::query()->create([
                'team_id' => $locked->team_id,
                'automation_run_id' => $locked->id,
                'automation_run_item_id' => $item?->id,
                'browser_session_id' => $session?->id,
                'sequence' => $sequence,
                'action_type' => $type,
                'policy_decision' => $decision,
                'input_summary' => $input,
                'output_summary' => $output,
                'started_at' => now(),
                'completed_at' => $complete ? now() : null,
            ]);
        });
    }

    private function closeOpenSessions(AutomationRun $run): void
    {
        $sessions = $run->browserSessions()
            ->whereNotIn('status', [BrowserSessionStatus::Closed->value, BrowserSessionStatus::Expired->value])
            ->get();

        foreach ($sessions as $session) {
            $this->closeSession->handle($session);
        }
    }

    private function createSnapshot(AutomationRun $run, CartInspection $cart): CartSnapshot
    {
        $remoteLines = array_map($this->sanitizeCartLine(...), $cart->lines);
        $baseline = $run->existing_cart_decision === ExistingCartDecision::Merge
            ? $this->baselineLines($run)
            : [];
        $snapshotPayload = [
            'run_id' => $run->id,
            'shopping_list_revision_id' => $run->shopping_list_revision_id,
            'lines' => $remoteLines,
            'total' => $cart->total,
            'currency' => $cart->currency,
        ];
        $checksum = hash('sha256', json_encode($snapshotPayload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $existing = $run->snapshots()->where('checksum', $checksum)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($run, $cart, $remoteLines, $baseline, $checksum): CartSnapshot {
            $version = ((int) $run->snapshots()->max('version')) + 1;
            $lineRecords = [];
            $usedRemoteIndexes = [];

            foreach ($baseline as $baselineLine) {
                $lineRecords[] = [
                    ...$this->lineRecord($baselineLine),
                    'classification' => CartLineClassification::PreExisting,
                    'pre_existing' => true,
                ];
            }

            foreach ($run->items()->orderBy('position')->get() as $item) {
                if ($item->status === AutomationRunItemStatus::Unavailable) {
                    $lineRecords[] = [
                        'automation_run_item_id' => $item->id,
                        'shopping_list_item_id' => $item->shopping_list_item_id,
                        'classification' => CartLineClassification::Unavailable,
                        'product_name' => (string) ($item->requirement_snapshot['name'] ?? 'Unavailable item'),
                        'quantity' => $item->requirement_snapshot['quantity'] ?? null,
                        'unit' => $item->requirement_snapshot['unit'] ?? null,
                        'pre_existing' => false,
                        'metadata' => ['reason' => $item->failure_message],
                    ];

                    continue;
                }

                if (! in_array($item->status, [AutomationRunItemStatus::Matched, AutomationRunItemStatus::Substituted], true)) {
                    continue;
                }

                $match = $this->findRemoteLineWithIndex($remoteLines, $item->matched_product, $item, $usedRemoteIndexes);
                if ($match === null) {
                    $lineRecords[] = [
                        'automation_run_item_id' => $item->id,
                        'shopping_list_item_id' => $item->shopping_list_item_id,
                        'classification' => CartLineClassification::Unresolved,
                        'product_name' => (string) ($item->requirement_snapshot['name'] ?? 'Unresolved item'),
                        'quantity' => $item->requirement_snapshot['quantity'] ?? null,
                        'unit' => $item->requirement_snapshot['unit'] ?? null,
                        'pre_existing' => false,
                    ];

                    continue;
                }

                [$index, $remoteLine] = $match;
                $usedRemoteIndexes[] = $index;
                $baselineLine = $this->findEquivalentLine($baseline, $remoteLine);
                $record = $this->lineRecord($remoteLine);

                if ($baselineLine !== null) {
                    $record['quantity'] = $this->positiveDifference($remoteLine['quantity'] ?? null, $baselineLine['quantity'] ?? null);
                    $record['total_price'] = $this->positiveDifference($remoteLine['total_price'] ?? null, $baselineLine['total_price'] ?? null);
                    $record['metadata'] = ['shared_remote_line_with_pre_existing_cart' => true];
                }

                $lineRecords[] = [
                    ...$record,
                    'automation_run_item_id' => $item->id,
                    'shopping_list_item_id' => $item->shopping_list_item_id,
                    'classification' => $this->classifySnapshotLine($item, $record),
                    'pre_existing' => false,
                ];
            }

            foreach ($remoteLines as $index => $remoteLine) {
                if (in_array($index, $usedRemoteIndexes, true) || $this->findEquivalentLine($baseline, $remoteLine) !== null) {
                    continue;
                }

                $lineRecords[] = [
                    ...$this->lineRecord($remoteLine),
                    'classification' => CartLineClassification::Unresolved,
                    'pre_existing' => false,
                    'metadata' => ['reason' => 'Unexpected remote cart line'],
                ];
            }

            $chefSubtotal = collect($lineRecords)
                ->reject(fn ($line) => (bool) ($line['pre_existing'] ?? false))
                ->sum(fn ($line) => is_numeric($line['total_price'] ?? null) ? (float) $line['total_price'] : 0);
            $snapshot = $run->snapshots()->create([
                'team_id' => $run->team_id,
                'created_by_user_id' => $run->started_by_user_id,
                'version' => $version,
                'currency' => $cart->currency,
                'chef_subtotal' => round($chefSubtotal, 2),
                'cart_total' => $cart->total,
                'checksum' => $checksum,
                'captured_at' => now(),
            ]);

            foreach ($lineRecords as $line) {
                $snapshot->lines()->create(['team_id' => $run->team_id, ...$line]);
            }

            return $snapshot->load('lines');
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function baselineLines(AutomationRun $run): array
    {
        $intervention = $run->interventions()
            ->where('type', AutomationInterventionType::ExistingCart->value)
            ->where('status', AutomationInterventionStatus::Resolved->value)
            ->oldest('requested_at')
            ->first();

        return is_array($intervention?->payload['lines'] ?? null)
            ? array_map($this->sanitizeCartLine(...), $intervention->payload['lines'])
            : [];
    }

    /** @param array<int, array<string, mixed>> $lines
     * @param  array<string, mixed>|null  $product
     * @return array<string, mixed>|null
     */
    private function findRemoteLine(array $lines, ?array $product, AutomationRunItem $item): ?array
    {
        $match = $this->findRemoteLineWithIndex($lines, $product, $item, []);

        return $match[1] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>|null  $product
     * @param  array<int, int>  $excludedIndexes
     * @return array{int, array<string, mixed>}|null
     */
    private function findRemoteLineWithIndex(array $lines, ?array $product, AutomationRunItem $item, array $excludedIndexes): ?array
    {
        $externalId = is_string($product['external_product_id'] ?? null) ? $product['external_product_id'] : null;
        $productName = is_string($product['product_name'] ?? null) ? $product['product_name'] : null;
        $requiredName = (string) ($item->requirement_snapshot['name'] ?? '');

        foreach ($lines as $index => $line) {
            if (in_array($index, $excludedIndexes, true)) {
                continue;
            }

            if ($externalId !== null && ($line['external_product_id'] ?? null) === $externalId) {
                return [$index, $line];
            }

            $lineName = (string) ($line['product_name'] ?? '');
            if (($productName !== null && $this->normalise($lineName) === $this->normalise($productName))
                || ($productName === null && $this->normalise($lineName) === $this->normalise($requiredName))) {
                return [$index, $line];
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $lines
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>|null
     */
    private function findEquivalentLine(array $lines, array $target): ?array
    {
        foreach ($lines as $line) {
            if (is_string($target['external_product_id'] ?? null)
                && ($line['external_product_id'] ?? null) === $target['external_product_id']) {
                return $line;
            }

            if ($this->normalise((string) ($line['product_name'] ?? '')) === $this->normalise((string) ($target['product_name'] ?? ''))) {
                return $line;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $line */
    private function classifySnapshotLine(AutomationRunItem $item, array $line): CartLineClassification
    {
        if ($item->status === AutomationRunItemStatus::Substituted) {
            return CartLineClassification::Substituted;
        }

        $expectedQuantity = is_numeric($item->requirement_snapshot['product_match']['pack_count'] ?? null)
            ? max(1, (float) $item->requirement_snapshot['product_match']['pack_count'])
            : 1.0;
        $observedQuantity = $line['quantity'] ?? null;

        if (is_numeric($observedQuantity) && abs((float) $observedQuantity - $expectedQuantity) > 0.001) {
            return CartLineClassification::QuantityAdjusted;
        }

        $estimatedPrice = $item->requirement_snapshot['estimated_price'] ?? null;
        $observedPrice = $line['total_price'] ?? null;

        if (is_numeric($estimatedPrice)
            && is_numeric($observedPrice)
            && abs((float) $observedPrice - (float) $estimatedPrice) > 0.01) {
            return CartLineClassification::PriceChanged;
        }

        return CartLineClassification::Matched;
    }

    /** @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function lineRecord(array $line): array
    {
        return [
            'external_product_id' => $line['external_product_id'] ?? null,
            'product_name' => (string) ($line['product_name'] ?? 'Unknown cart item'),
            'quantity' => is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : null,
            'unit' => is_string($line['unit'] ?? null) ? $line['unit'] : null,
            'unit_price' => is_numeric($line['unit_price'] ?? null) ? (float) $line['unit_price'] : null,
            'total_price' => is_numeric($line['total_price'] ?? null) ? (float) $line['total_price'] : null,
        ];
    }

    /** @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function sanitizeCartLine(array $line): array
    {
        return $this->lineRecord($line);
    }

    /** @param array<string, mixed>|null $product
     * @return array<string, mixed>|null
     */
    private function sanitizeProduct(?array $product): ?array
    {
        return $product === null ? null : $this->lineRecord($product);
    }

    /** @return array<string, mixed> */
    private function requirementSummary(AutomationRunItem $item): array
    {
        return [
            'name' => $item->requirement_snapshot['name'] ?? null,
            'quantity' => $item->requirement_snapshot['quantity'] ?? null,
            'unit' => $item->requirement_snapshot['unit'] ?? null,
        ];
    }

    /** @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function sanitizeAction(array $action): array
    {
        return array_filter([
            'type' => is_string($action['type'] ?? null) ? $action['type'] : null,
            'x' => is_numeric($action['x'] ?? null) ? (int) $action['x'] : null,
            'y' => is_numeric($action['y'] ?? null) ? (int) $action['y'] : null,
            'scroll_x' => is_numeric($action['scroll_x'] ?? null) ? (int) $action['scroll_x'] : null,
            'scroll_y' => is_numeric($action['scroll_y'] ?? null) ? (int) $action['scroll_y'] : null,
            'text_length' => is_string($action['text'] ?? null) ? Str::length($action['text']) : null,
            'key' => is_string($action['key'] ?? null) ? $action['key'] : null,
        ], fn ($value) => $value !== null);
    }

    private function safeHost(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    private function normalise(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function positiveDifference(mixed $value, mixed $baseline): ?float
    {
        if (! is_numeric($value) || ! is_numeric($baseline)) {
            return is_numeric($value) ? (float) $value : null;
        }

        return max(0, (float) $value - (float) $baseline);
    }
}
