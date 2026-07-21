<?php

namespace App\Actions\Automation;

use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationRunItemStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\ExistingCartDecision;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\AutomationIntervention;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveAutomationIntervention
{
    public function __construct(
        private readonly TransitionAutomationRun $transition,
        private readonly CancelAutomationRun $cancelRun,
    ) {}

    /** @param array<string, mixed> $resolution */
    public function handle(AutomationIntervention $intervention, User $user, array $resolution): void
    {
        if (! $user->can('update', $intervention)) {
            throw new AuthorizationException('You cannot resolve this cart decision.');
        }

        if ($intervention->status !== AutomationInterventionStatus::Pending) {
            return;
        }

        $choice = is_string($resolution['choice'] ?? null) ? $resolution['choice'] : '';

        if ($choice === 'cancel') {
            $this->cancelRun->handle($intervention->run, $user);

            return;
        }

        match ($intervention->type) {
            AutomationInterventionType::ExistingCart => $this->resolveExistingCart($intervention, $user, $choice),
            AutomationInterventionType::CartChanged => $intervention->automation_run_item_id === null
                ? $this->resolveExistingCart($intervention, $user, $choice)
                : $this->resolveItem($intervention, $user, $choice, $resolution),
            AutomationInterventionType::ItemDecision,
            AutomationInterventionType::PriceLimit,
            AutomationInterventionType::Substitution => $this->resolveItem($intervention, $user, $choice, $resolution),
            AutomationInterventionType::Reauthentication => throw ValidationException::withMessages([
                'resolution' => 'Finish signing in through the secure Woolworths connection screen instead.',
            ]),
            default => throw ValidationException::withMessages([
                'resolution' => 'This safety pause cannot be overridden. Cancel the run or retry after the retailer page is safe.',
            ]),
        };
    }

    private function resolveExistingCart(AutomationIntervention $intervention, User $user, string $choice): void
    {
        $decision = ExistingCartDecision::tryFrom($choice);

        if (! in_array($decision, [ExistingCartDecision::Merge, ExistingCartDecision::Replace], true)) {
            throw ValidationException::withMessages(['resolution' => 'Choose merge, replace, or cancel.']);
        }

        DB::transaction(function () use ($intervention, $user, $decision): void {
            $locked = AutomationIntervention::query()->lockForUpdate()->findOrFail($intervention->id);

            if ($locked->status !== AutomationInterventionStatus::Pending) {
                return;
            }

            $locked->update([
                'status' => AutomationInterventionStatus::Resolved,
                'resolution' => ['choice' => $decision->value],
                'resolved_by_user_id' => $user->id,
                'resolved_at' => now(),
            ]);
            $run = $locked->run;
            $run->existing_cart_decision = $decision;
            $run->save();
            $this->transition->handle($run, AutomationRunStatus::Queued);
        });

        $this->dispatch($intervention->automation_run_id);
    }

    /** @param array<string, mixed> $resolution */
    private function resolveItem(AutomationIntervention $intervention, User $user, string $choice, array $resolution): void
    {
        if (! in_array($choice, ['retry', 'skip', 'accept_substitution', 'accept_product'], true)) {
            throw ValidationException::withMessages(['resolution' => 'Choose retry, skip, accept the proposed substitution, or cancel.']);
        }

        DB::transaction(function () use ($intervention, $user, $choice, $resolution): void {
            $locked = AutomationIntervention::query()->lockForUpdate()->findOrFail($intervention->id);
            $item = $locked->runItem;

            if ($locked->status !== AutomationInterventionStatus::Pending || $item === null) {
                return;
            }

            $locked->update([
                'status' => AutomationInterventionStatus::Resolved,
                'resolution' => ['choice' => $choice],
                'resolved_by_user_id' => $user->id,
                'resolved_at' => now(),
            ]);

            if ($choice === 'skip') {
                $item->update(['status' => AutomationRunItemStatus::Skipped, 'resolved_at' => now()]);
            } elseif (in_array($choice, ['accept_substitution', 'accept_product'], true)) {
                $product = is_array($resolution['product'] ?? null)
                    ? $resolution['product']
                    : (is_array($locked->payload['product'] ?? null) ? $locked->payload['product'] : null);
                $item->update([
                    'status' => $choice === 'accept_substitution'
                        ? AutomationRunItemStatus::Substituted
                        : AutomationRunItemStatus::Matched,
                    'matched_product' => $product,
                    'resolved_at' => now(),
                ]);
            } else {
                $item->update(['status' => AutomationRunItemStatus::Pending, 'failure_message' => null]);
            }

            $this->transition->handle($locked->run, AutomationRunStatus::Queued);
        });

        $this->dispatch($intervention->automation_run_id);
    }

    private function dispatch(int $runId): void
    {
        AdvanceAutomationRunJob::dispatch($runId)->onQueue((string) config('automation.queue', 'automation'));
    }
}
