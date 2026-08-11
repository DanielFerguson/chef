<?php

namespace App\Actions\Baskets;

use App\Enums\BasketSnapshotKind;
use App\Models\BasketRun;
use App\Models\BasketSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class RecordBasketSnapshot
{
    /** @param array<string, mixed> $inspection */
    public function handle(
        BasketRun $basketRun,
        BasketSnapshotKind $kind,
        array $inspection,
    ): BasketSnapshot {
        $rawLines = Arr::get($inspection, 'lines');
        if (! is_array($rawLines)) {
            throw ValidationException::withMessages([
                'basket' => 'The retailer returned invalid basket lines.',
            ]);
        }

        $normalizedLines = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages([
                    'basket' => 'The retailer returned an invalid basket line.',
                ]);
            }

            $sku = trim((string) Arr::get($line, 'sku'));
            $title = trim((string) Arr::get($line, 'title'));
            $quantity = (int) Arr::get($line, 'absolute_quantity');

            if ($sku === '' || $title === '' || $quantity < 1) {
                throw ValidationException::withMessages([
                    'basket' => 'The retailer returned an incomplete basket line.',
                ]);
            }

            $normalized = [
                'sku' => $sku,
                'product_title' => $title,
                'absolute_quantity' => $quantity,
                'unit_price_cents' => is_numeric(Arr::get($line, 'unit_price_cents'))
                    ? (int) Arr::get($line, 'unit_price_cents')
                    : null,
                'line_price_cents' => is_numeric(Arr::get($line, 'line_price_cents'))
                    ? (int) Arr::get($line, 'line_price_cents')
                    : null,
            ];
            $normalized['checksum'] = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
            $normalizedLines[] = $normalized;
        }

        $lines = collect($normalizedLines)
            ->sortBy('sku')
            ->values();

        if ($lines->pluck('sku')->unique()->count() !== $lines->count()) {
            throw ValidationException::withMessages([
                'basket' => 'The retailer returned duplicate basket product identifiers.',
            ]);
        }

        $retailerTotalCents = is_numeric(Arr::get($inspection, 'retailer_total_cents'))
            ? (int) Arr::get($inspection, 'retailer_total_cents')
            : null;
        $checksum = hash('sha256', json_encode([
            'lines' => $lines->map(fn (array $line): array => Arr::except($line, 'checksum'))->all(),
            'retailer_total_cents' => $retailerTotalCents,
        ], JSON_THROW_ON_ERROR));
        $existing = $basketRun->snapshots()->where('kind', $kind)->first();

        if ($existing !== null) {
            if ($existing->checksum !== $checksum) {
                throw ValidationException::withMessages([
                    'basket' => "The durable {$kind->value} basket snapshot cannot be overwritten.",
                ]);
            }

            return $existing->load('lines');
        }

        $snapshot = $basketRun->snapshots()->create([
            'team_id' => $basketRun->team_id,
            'kind' => $kind,
            'retailer_total_cents' => $retailerTotalCents,
            'line_count' => $lines->count(),
            'checksum' => $checksum,
            'captured_at' => now(),
        ]);

        foreach ($lines as $line) {
            $snapshot->lines()->create([
                'team_id' => $basketRun->team_id,
                ...$line,
            ]);
        }

        return $snapshot->load('lines');
    }

    /**
     * @param  iterable<array{sku: string, title: string, absolute_quantity: int, unit_price_cents?: int|null, line_price_cents?: int|null}>  $lines
     * @return array<string, mixed>
     */
    public function inspection(iterable $lines, ?int $retailerTotalCents = null): array
    {
        return [
            'lines' => collect($lines)->values()->all(),
            'retailer_total_cents' => $retailerTotalCents,
        ];
    }
}
