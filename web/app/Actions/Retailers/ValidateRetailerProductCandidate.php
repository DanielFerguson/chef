<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerProvider;
use App\Models\GroceryRequirement;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ValidateRetailerProductCandidate
{
    /** @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function handle(GroceryRequirement $requirement, array $candidate): array
    {
        $rejectionCodes = [];
        $allowedHosts = config('retailer.providers.coles.allowed_hosts', []);
        $originHost = Str::lower((string) Arr::get($candidate, 'origin_host'));
        $sku = trim((string) Arr::get($candidate, 'sku'));
        $title = Str::squish((string) Arr::get($candidate, 'title'));
        $packQuantity = is_numeric(Arr::get($candidate, 'pack_quantity'))
            ? (float) Arr::get($candidate, 'pack_quantity')
            : null;
        $packUnit = Str::lower(trim((string) Arr::get($candidate, 'pack_unit')));
        $priceCents = is_numeric(Arr::get($candidate, 'price_cents'))
            ? (int) Arr::get($candidate, 'price_cents')
            : null;
        $available = Arr::get($candidate, 'available') === true;
        $labelEvidence = Arr::get($candidate, 'label_evidence', []);
        $attributeEvidence = Arr::get($candidate, 'attribute_evidence', []);
        $isHomeBrand = $this->evidencedBoolean($candidate, $attributeEvidence, 'is_home_brand', 'home_brand');
        $isOrganic = $this->evidencedBoolean($candidate, $attributeEvidence, 'is_organic', 'organic');

        if (! in_array($originHost, $allowedHosts, true)) {
            $rejectionCodes[] = 'wrong_origin';
        }
        if ($sku === '' || preg_match('/^[A-Za-z0-9._-]+$/', $sku) !== 1) {
            $rejectionCodes[] = 'invalid_sku';
        }
        if ($title === '') {
            $rejectionCodes[] = 'missing_title';
        }
        if (! $available) {
            $rejectionCodes[] = 'unavailable';
        }
        if (Arr::get($candidate, 'restricted_product') === true) {
            $rejectionCodes[] = 'restricted_product';
        }
        if ($packQuantity === null || $packQuantity <= 0 || ! in_array($packUnit, ['g', 'ml', 'each'], true)) {
            $rejectionCodes[] = 'invalid_pack_data';
        }
        if ($priceCents === null || $priceCents <= 0) {
            $rejectionCodes[] = 'invalid_price_data';
        }
        if (! $requirement->quantity_unknown && $requirement->unit !== $packUnit) {
            $rejectionCodes[] = 'incompatible_pack_unit';
        }

        foreach ($this->applicableSafetyConstraints($requirement) as $constraint) {
            $constraintEvidence = is_array($labelEvidence)
                ? Arr::get($labelEvidence, 'constraints')
                : null;
            $evidence = null;
            if (is_array($constraintEvidence)) {
                foreach ($constraintEvidence as $item) {
                    if (is_array($item)
                        && (int) Arr::get($item, 'constraint_id') === (int) $constraint['constraint_id']) {
                        $evidence = $item;
                        break;
                    }
                }
            }
            $evidenceStatus = is_array($evidence) ? Arr::get($evidence, 'status') : null;
            $evidenceSource = is_array($evidence) ? trim((string) Arr::get($evidence, 'source')) : '';

            if ($evidenceStatus === 'conflict') {
                $rejectionCodes[] = 'known_safety_conflict';
            } elseif ($evidenceStatus !== 'compatible' || $evidenceSource === '') {
                $rejectionCodes[] = 'insufficient_constraint_evidence';
            }
        }

        $semanticKey = Str::squish((string) Arr::get($candidate, 'semantic_key'));
        if ($semanticKey === '') {
            $semanticKey = Str::of($title)
                ->lower()
                ->replaceMatches('/\b\d+(?:\.\d+)?\s*(?:kg|g|l|ml|pack)\b/u', '')
                ->squish()
                ->toString();
        }

        $normalized = [
            'provider' => RetailerProvider::Coles,
            'sku' => $sku,
            'title' => $title,
            'brand' => filled(Arr::get($candidate, 'brand'))
                ? Str::squish((string) Arr::get($candidate, 'brand'))
                : null,
            'is_home_brand' => $isHomeBrand,
            'is_organic' => $isOrganic,
            'attribute_evidence' => is_array($attributeEvidence) ? $attributeEvidence : [],
            'semantic_key' => $semanticKey,
            'origin_host' => $originHost,
            'product_path' => filled(Arr::get($candidate, 'product_path'))
                ? (string) Arr::get($candidate, 'product_path')
                : null,
            'pack_quantity' => $packQuantity,
            'pack_unit' => $packUnit,
            'price_cents' => $priceCents,
            'available' => $available,
            'status' => $rejectionCodes === []
                ? RetailerCandidateStatus::Eligible
                : RetailerCandidateStatus::Rejected,
            'rejection_codes' => array_values(array_unique($rejectionCodes)),
            'label_evidence' => is_array($labelEvidence) ? $labelEvidence : [],
            'captured_at' => now(),
        ];
        $normalized['fingerprint'] = hash('sha256', json_encode([
            'provider' => RetailerProvider::Coles->value,
            'sku' => $normalized['sku'],
            'pack_quantity' => $normalized['pack_quantity'],
            'pack_unit' => $normalized['pack_unit'],
            'price_cents' => $normalized['price_cents'],
            'available' => $normalized['available'],
            'label_evidence' => $normalized['label_evidence'],
            'is_home_brand' => $normalized['is_home_brand'],
            'is_organic' => $normalized['is_organic'],
            'attribute_evidence' => $normalized['attribute_evidence'],
        ], JSON_THROW_ON_ERROR));

        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private function applicableSafetyConstraints(GroceryRequirement $requirement): array
    {
        return array_values(array_filter(
            $requirement->applicable_constraints ?? [],
            fn (array $constraint): bool => in_array(Arr::get($constraint, 'kind'), [
                'allergy',
                'medical',
                'dietary',
                'religious',
            ], true),
        ));
    }

    /** @param array<string, mixed> $candidate */
    private function evidencedBoolean(
        array $candidate,
        mixed $attributeEvidence,
        string $candidateKey,
        string $evidenceKey,
    ): ?bool {
        $value = Arr::get($candidate, $candidateKey);
        $evidence = is_array($attributeEvidence)
            ? Arr::get($attributeEvidence, $evidenceKey)
            : null;
        $source = is_array($evidence) ? trim((string) Arr::get($evidence, 'source')) : '';

        return is_bool($value) && $source !== '' ? $value : null;
    }
}
