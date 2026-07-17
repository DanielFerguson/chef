<?php

namespace App\Actions\Automation;

use App\Enums\AutomationReconciliationStatus;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReconcileAutomationRun
{
    /** @param array<int, array<string, mixed>> $lines */
    public function handle(AutomationRun $run, array $lines): void
    {
        Validator::make(['lines' => $lines], [
            'lines' => ['array', 'max:200'],
            'lines.*.shopping_list_item_id' => ['nullable', 'integer'],
            'lines.*.status' => ['required', 'string'],
            'lines.*.intended_name' => ['required', 'string', 'max:255'],
            'lines.*.product_name' => ['nullable', 'string', 'max:255'],
            'lines.*.retailer_product_identifier' => ['nullable', 'string', 'max:255'],
            'lines.*.brand' => ['nullable', 'string', 'max:255'],
            'lines.*.pack' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'lines.*.total_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'lines.*.substitution_reason' => ['nullable', 'string', 'max:2000'],
            'lines.*.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ])->validate();

        $scopeItemIds = collect($run->scopeItems())->pluck('shopping_list_item_id')->map(fn ($id): int => (int) $id);

        DB::transaction(function () use ($run, $lines, $scopeItemIds): void {
            $run->reconciliations()->delete();

            foreach ($lines as $line) {
                $status = AutomationReconciliationStatus::tryFrom((string) $line['status']);
                $itemId = isset($line['shopping_list_item_id']) ? (int) $line['shopping_list_item_id'] : null;

                if ($status === null) {
                    throw ValidationException::withMessages(['reconciliation' => 'The computer-use result contained an unknown reconciliation status.']);
                }

                if ($itemId !== null && ! $scopeItemIds->contains($itemId)) {
                    throw ValidationException::withMessages(['reconciliation' => 'The computer-use result referenced an item outside the frozen shopping list.']);
                }

                if ($status !== AutomationReconciliationStatus::Extra && $itemId === null) {
                    throw ValidationException::withMessages(['reconciliation' => 'A reconciled shopping item must identify its frozen list row.']);
                }

                $run->reconciliations()->create([
                    'team_id' => $run->team_id,
                    'shopping_list_item_id' => $itemId,
                    'status' => $status,
                    'intended_name' => trim((string) $line['intended_name']),
                    'retailer_product_identifier' => $line['retailer_product_identifier'] ?? null,
                    'product_name' => $line['product_name'] ?? null,
                    'brand' => $line['brand'] ?? null,
                    'pack' => $line['pack'] ?? null,
                    'quantity' => $line['quantity'] ?? null,
                    'unit_price' => $line['unit_price'] ?? null,
                    'total_price' => $line['total_price'] ?? null,
                    'substitution_reason' => $line['substitution_reason'] ?? null,
                    'confidence' => $line['confidence'] ?? null,
                    'raw_data' => $line,
                ]);
            }
        });
    }
}
