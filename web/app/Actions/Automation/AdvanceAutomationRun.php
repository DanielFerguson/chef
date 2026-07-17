<?php

namespace App\Actions\Automation;

use App\Automation\ComputerActionPolicy;
use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\RetailerOriginPolicy;
use App\Enums\AutomationReconciliationStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdvanceAutomationRun
{
    public function __construct(
        private readonly ComputerUseEngine $engine,
        private readonly ComputerActionPolicy $actions,
        private readonly RetailerOriginPolicy $origins,
        private readonly ReconcileAutomationRun $reconcile,
        private readonly CreateAutomationApproval $createApproval,
    ) {}

    public function handle(AutomationRun $run): AutomationRun
    {
        $claimed = DB::transaction(function () use ($run): ?AutomationRun {
            $run = AutomationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== AutomationRunStatus::Queued) {
                return null;
            }

            if ($run->expires_at->isPast()) {
                $run->update(['status' => AutomationRunStatus::Expired, 'finished_at' => now()]);

                return null;
            }

            $this->origins->assertAllowed($run->retailer, (string) $run->current_url);
            $run->update(['status' => AutomationRunStatus::Processing]);

            return $run->refresh();
        });

        if ($claimed === null) {
            return $run->refresh();
        }

        try {
            $result = $this->engine->advance($claimed->load('retailer', 'steps'));
            $run = DB::transaction(function () use ($claimed, $result): AutomationRun {
                $run = AutomationRun::query()->whereKey($claimed->id)->lockForUpdate()->firstOrFail();

                if ($run->status !== AutomationRunStatus::Processing) {
                    return $run->refresh();
                }

                if ($result->complete) {
                    $this->reconcile->handle($run, $this->completeReconciliation($run, $result->reconciliation));
                    $run->update([
                        'previous_response_id' => $result->responseId,
                        'status' => AutomationRunStatus::AwaitingReview,
                        'progress' => $this->progress($run->refresh()),
                        'pause_reason' => $result->message,
                    ]);
                    $this->addFinalReviewApprovals($run);

                    if ($run->approvals()->where('status', 'pending')->exists()) {
                        $run->update(['status' => AutomationRunStatus::AwaitingApproval]);
                    }

                    return $run->refresh();
                }

                $this->actions->assertAllowed($result->actions);
                $sequence = ((int) $run->steps()->max('sequence')) + 1;
                $step = $run->steps()->create([
                    'team_id' => $run->team_id,
                    'sequence' => $sequence,
                    'response_id' => $result->responseId,
                    'call_id' => $result->callId,
                    'status' => $result->safetyChecks === [] ? AutomationStepStatus::Ready : AutomationStepStatus::AwaitingApproval,
                    'actions' => $result->actions,
                    'safety_checks' => $result->safetyChecks,
                    'requested_at' => now(),
                ]);
                $run->update([
                    'previous_response_id' => $result->responseId,
                    'status' => $result->safetyChecks === [] ? AutomationRunStatus::Executing : AutomationRunStatus::AwaitingApproval,
                ]);

                foreach ($result->safetyChecks as $check) {
                    $this->createApproval->handle(
                        $run,
                        (string) ($check['code'] ?? 'model_safety_check'),
                        (string) ($check['message'] ?? 'Continue the proposed browser action'),
                        'Chef will execute this action only in the selected retailer tab. Checkout, payment, authentication, address changes, and order submission remain blocked.',
                        $step,
                    );
                }

                return $run->refresh();
            });
        } catch (Throwable $exception) {
            report($exception);
            $retrying = DB::transaction(function () use ($claimed): AutomationRun {
                $run = AutomationRun::query()->whereKey($claimed->id)->lockForUpdate()->firstOrFail();

                if ($run->status === AutomationRunStatus::Processing) {
                    $run->update([
                        'status' => AutomationRunStatus::Queued,
                        'error_code' => 'computer_use_retrying',
                        'error_message' => 'Chef is retrying after a temporary computer-use failure.',
                    ]);
                }

                return $run->refresh();
            });
            AutomationRunUpdated::dispatch($retrying);

            throw $exception;
        }

        AutomationRunUpdated::dispatch($run);

        return $run;
    }

    /**
     * @param  array<int, array<string, mixed>>  $provided
     * @return array<int, array<string, mixed>>
     */
    private function completeReconciliation(AutomationRun $run, array $provided): array
    {
        $byItem = collect($provided)->whereNotNull('shopping_list_item_id')->keyBy('shopping_list_item_id');
        $complete = collect($run->scopeItems())->map(function (array $item) use ($byItem): array {
            return $byItem->get($item['shopping_list_item_id']) ?? [
                'shopping_list_item_id' => $item['shopping_list_item_id'],
                'status' => AutomationReconciliationStatus::Unresolved->value,
                'intended_name' => $item['name'],
                'product_name' => null,
                'quantity' => null,
                'unit_price' => null,
                'total_price' => null,
                'confidence' => null,
            ];
        });

        return $complete
            ->concat(collect($provided)->whereNull('shopping_list_item_id'))
            ->values()
            ->all();
    }

    /** @return array{total: int, added: int, unresolved: int} */
    private function progress(AutomationRun $run): array
    {
        $reconciliations = $run->reconciliations()->get();

        return [
            'total' => count($run->scopeItems()),
            'added' => $reconciliations->whereIn('status', [AutomationReconciliationStatus::Matched, AutomationReconciliationStatus::Substituted])->count(),
            'unresolved' => $reconciliations->whereIn('status', [AutomationReconciliationStatus::Unresolved, AutomationReconciliationStatus::Unavailable])->count(),
        ];
    }

    private function addFinalReviewApprovals(AutomationRun $run): void
    {
        $budget = $run->approvedBudget();
        $total = (float) $run->reconciliations()->sum('total_price');

        if ($budget !== null && $total > (float) $budget) {
            $this->createApproval->handle(
                $run,
                'budget_exceeded',
                'Review a cart total of $'.number_format($total, 2),
                'The prepared cart is $'.number_format($total - (float) $budget, 2).' above the approved $'.number_format((float) $budget, 2).' budget. No checkout will occur.',
            );
        }

        $scope = collect($run->scopeItems())->keyBy('shopping_list_item_id');
        $restrictedSubstitutions = $run->reconciliations()
            ->where('status', AutomationReconciliationStatus::Substituted)
            ->get()
            ->filter(function ($line) use ($scope): bool {
                $preference = $scope->get($line->shopping_list_item_id)['preference'] ?? null;

                return is_array($preference) && ($preference['accept_substitutes'] ?? true) === false;
            });

        foreach ($restrictedSubstitutions as $line) {
            $this->createApproval->handle(
                $run,
                'material_substitution',
                'Review '.$line->product_name.' instead of '.$line->intended_name,
                'This household preference does not permit automatic substitutes. Approving keeps it in the reviewable cart; it does not place the order.',
            );
        }
    }
}
