<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\RetailerProductDiscovery;
use App\Models\Retailer;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WoolworthsCatalogueDiscovery implements RetailerProductDiscovery
{
    public function discover(Retailer $retailer, array $requirements): array
    {
        if ($retailer->slug !== 'woolworths' || $requirements === []) {
            return [];
        }

        $concurrency = max(1, min(8, (int) config('services.woolworths.discovery_concurrency', 4)));
        $maxCandidates = max(1, min(12, (int) config('services.woolworths.discovery_max_candidates', 8)));
        $url = (string) config('services.woolworths.catalogue_search_url', 'https://www.woolworths.com.au/apis/ui/Search/products');
        $timeout = max(5, (int) config('services.woolworths.discovery_timeout', 12));
        $searchTerms = array_map(
            fn (array $requirement): string => (string) $requirement['name'],
            $requirements,
        );
        $responses = $this->searchPool($searchTerms, $url, $maxCandidates, $timeout, $concurrency);
        $discovered = [];
        $failedRequests = 0;
        $retryIndexes = [];

        foreach ($requirements as $index => $requirement) {
            $response = $responses[(string) $index] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                $failedRequests++;
                $discovered[$index] = [];

                continue;
            }

            $candidates = $this->candidates($response->json(), $requirement, $maxCandidates);
            $discovered[$index] = $candidates;

            if ($this->usableCandidates($candidates) === []) {
                $normalized = $this->normalizeSearchTerm((string) $requirement['name']);

                if ($normalized !== '' && strcasecmp($normalized, (string) $requirement['name']) !== 0) {
                    $retryIndexes[$index] = $normalized;
                }
            }
        }

        if ($retryIndexes !== []) {
            $retryResponses = $this->searchPool(
                array_values($retryIndexes),
                $url,
                $maxCandidates,
                $timeout,
                $concurrency,
            );
            $retryPosition = 0;

            foreach ($retryIndexes as $index => $normalized) {
                $response = $retryResponses[(string) $retryPosition] ?? null;
                $retryPosition++;

                if (! $response instanceof Response || ! $response->successful()) {
                    $failedRequests++;

                    continue;
                }

                $retryCandidates = $this->candidates($response->json(), [
                    ...$requirements[$index],
                    'name' => $normalized,
                ], $maxCandidates);

                if ($this->usableCandidates($retryCandidates) !== []) {
                    $discovered[$index] = $retryCandidates;
                }
            }
        }

        if ($failedRequests > 0) {
            throw new RuntimeException('Woolworths catalogue discovery did not return a usable response.');
        }

        return $discovered;
    }

    /**
     * @param  array<int, string>  $searchTerms
     * @return array<string, Response|Throwable>
     */
    private function searchPool(
        array $searchTerms,
        string $url,
        int $maxCandidates,
        int $timeout,
        int $concurrency,
    ): array {
        return Http::pool(function (Pool $pool) use ($searchTerms, $url, $maxCandidates, $timeout): void {
            foreach ($searchTerms as $index => $searchTerm) {
                // Laravel's pool does not inherit a PendingRequest's
                // headers or timeouts. Configure every pooled request so
                // Woolworths receives the same bounded public-search
                // profile as a direct request.
                $pool->as((string) $index)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'Chef product-plan discovery'])
                    ->connectTimeout(5)
                    ->timeout($timeout)
                    ->get($url, [
                        'searchTerm' => $searchTerm,
                        'pageNumber' => 1,
                        'pageSize' => $maxCandidates,
                        'sortType' => 'TraderRelevance',
                    ]);
            }
        }, $concurrency);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function usableCandidates(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => (bool) ($candidate['in_stock'] ?? true)
                && filled($candidate['external_id'] ?? null)
                && filled($candidate['product_url'] ?? null),
        ));
    }

    public function normalizeSearchTerm(string $name): string
    {
        $value = Str::of($name)->squish()->lower()->toString();
        $noise = [
            'finely',
            'freshly',
            'fresh',
            'roughly',
            'thinly',
            'thickly',
            'diced',
            'sliced',
            'chopped',
            'minced',
            'crushed',
            'large',
            'medium',
            'small',
        ];

        foreach ($noise as $token) {
            $value = (string) preg_replace('/\b'.preg_quote($token, '/').'\b/u', ' ', $value);
        }

        return Str::of($value)->squish()->toString();
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @return array<int, array<string, mixed>>
     */
    private function candidates(mixed $payload, array $requirement, int $limit): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $products = collect($this->collectProducts($payload))
            ->reject(fn (array $product): bool => (bool) ($product['IsMarketProduct'] ?? false))
            ->sortBy(fn (array $product): int => Str::contains(Str::lower((string) ($product['Source'] ?? '')), 'promoted') ? 1 : 0)
            ->values();

        return $products
            ->map(function (array $product, int $rank) use ($requirement): ?array {
                $externalId = $product['Stockcode'] ?? $product['StockCode'] ?? $product['ProductId'] ?? $product['Id'] ?? null;
                $name = $product['Name'] ?? $product['DisplayName'] ?? null;

                if ((! is_string($externalId) && ! is_numeric($externalId)) || ! is_string($name) || trim($name) === '') {
                    return null;
                }

                $externalId = (string) $externalId;
                $packSize = is_string($product['PackageSize'] ?? null) && trim($product['PackageSize']) !== ''
                    ? Str::squish($product['PackageSize'])
                    : (is_string($product['CupString'] ?? null) ? Str::squish($product['CupString']) : null);

                return [
                    'external_id' => $externalId,
                    'product_name' => Str::squish($name),
                    'product_url' => 'https://www.woolworths.com.au/shop/productdetails/'.rawurlencode($externalId),
                    'price' => $this->numeric($product['Price'] ?? $product['CupPrice'] ?? null),
                    'pack_size' => $packSize,
                    'pack_count' => $this->packCount($requirement, $name, $packSize),
                    'in_stock' => (! array_key_exists('IsInStock', $product) || (bool) $product['IsInStock'])
                        && (! array_key_exists('IsAvailable', $product) || (bool) $product['IsAvailable']),
                    'confidence' => $this->candidateConfidence((string) $requirement['name'], $name, $rank),
                    'source' => 'woolworths_public_catalogue',
                ];
            })
            ->filter()
            ->unique('external_id')
            ->sortByDesc('confidence')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string|int, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    private function collectProducts(array $node): array
    {
        if ((isset($node['Stockcode']) || isset($node['StockCode']) || isset($node['ProductId']))
            && (isset($node['Name']) || isset($node['DisplayName']))) {
            $product = [];

            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $product[$key] = $value;
                }
            }

            return [$product];
        }

        $products = [];

        foreach ($node as $value) {
            if (is_array($value)) {
                array_push($products, ...$this->collectProducts($value));
            }
        }

        return $products;
    }

    private function nameConfidence(string $requirement, string $candidate): float
    {
        $requiredTokens = array_values(array_diff($this->tokens($requirement), [
            'canned',
            'chopped',
            'crushed',
            'diced',
            'dried',
            'fine',
            'finely',
            'flat',
            'fresh',
            'freshly',
            'fry',
            'large',
            'leaf',
            'medium',
            'minced',
            'mix',
            'roughly',
            'sliced',
            'small',
            'stir',
            'thickly',
            'thinly',
        ]));
        $candidateTokens = $this->tokens($candidate);

        if ($requiredTokens === [] || $candidateTokens === []) {
            return 0;
        }

        $overlap = count(array_intersect($requiredTokens, $candidateTokens));

        return round((($overlap / count($requiredTokens)) * 0.8) + (($overlap / count($candidateTokens)) * 0.2), 4);
    }

    private function candidateConfidence(string $requirement, string $candidate, int $rank): float
    {
        $relevance = match ($rank) {
            0 => 1.0,
            1 => 0.55,
            2 => 0.35,
            3 => 0.2,
            default => 0.1,
        };
        $confidence = ($relevance * 0.65) + ($this->nameConfidence($requirement, $candidate) * 0.35);
        $requiredTokens = $this->tokens($requirement);
        $candidateTokens = $this->tokens($candidate);
        $materialVariants = [
            'cutter',
            'dog',
            'dumpling',
            'jerky',
            'juice',
            'medley',
            'microwave',
            'moroccan',
            'pearl',
            'pet',
            'seed',
        ];

        if (array_diff(array_intersect($candidateTokens, $materialVariants), $requiredTokens) !== []) {
            $confidence -= 0.18;
        }

        return round(max(0, min(1, $confidence)), 4);
    }

    /** @return array<int, string> */
    private function tokens(string $value): array
    {
        try {
            $value = Str::of($value)
                ->lower()
                ->ascii()
                ->replaceMatches('/\bcous\s+cous\b/', 'couscous')
                ->replaceMatches('/\bbread\s+crumbs?\b/', 'breadcrumbs')
                ->replaceMatches('/[^a-z0-9]+/', ' ')
                ->squish()
                ->toString();
        } catch (Throwable) {
            return [];
        }

        return collect(explode(' ', $value))
            ->filter(fn (string $token) => strlen($token) > 1)
            ->map(fn (string $token): string => match ($token) {
                'beans' => 'bean',
                'breadcrumbs' => 'breadcrumb',
                'eggs' => 'egg',
                'fillets' => 'fillet',
                'flakes' => 'flake',
                'lentils' => 'lentil',
                'onions' => 'onion',
                'potatoes' => 'potato',
                'strips' => 'strip',
                'tomatoes' => 'tomato',
                'tortillas' => 'tortilla',
                default => $token,
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $requirement */
    private function packCount(array $requirement, string $productName, ?string $packSize): int
    {
        $quantity = $this->numeric($requirement['quantity'] ?? null);

        if ($quantity === null || $quantity <= 0 || ! is_string($requirement['unit'] ?? null)) {
            return 1;
        }

        $unit = Str::lower(trim($requirement['unit']));
        $parsedPack = $this->parsePackSize($packSize);

        if ($parsedPack !== null) {
            [$packQuantity, $packUnit] = $parsedPack;
            $requiredBase = $this->baseQuantity($quantity, $unit);
            $packBase = $this->baseQuantity($packQuantity, $packUnit);

            if ($requiredBase !== null && $packBase !== null && $requiredBase[1] === $packBase[1]) {
                return max(1, (int) ceil($requiredBase[0] / $packBase[0]));
            }

            if ($this->isEachUnit($unit) && $packUnit === 'each') {
                if (Str::contains(Str::lower($productName), ['bag', 'bunch', 'carton', 'dozen', 'pack', 'punnet', 'tray'])) {
                    return 1;
                }

                return max(1, (int) ceil($quantity / $packQuantity));
            }
        }

        return $this->isEachUnit($unit) ? max(1, (int) ceil($quantity)) : 1;
    }

    /** @return array{float, string}|null */
    private function parsePackSize(?string $packSize): ?array
    {
        if ($packSize === null) {
            return null;
        }

        $value = Str::lower(Str::squish($packSize));

        if (preg_match('/^(?:per\s*)?(\d+(?:\.\d+)?)\s*(kg|g|ml|l|litre|litres)$/', $value, $matches) === 1) {
            return [(float) $matches[1], $matches[2]];
        }

        if (preg_match('/^(\d+)\s*(?:pack|pk)$/', $value, $matches) === 1) {
            return [(float) $matches[1], 'each'];
        }

        if (in_array($value, ['each', '1ea', '1 ea'], true)) {
            return [1.0, 'each'];
        }

        return null;
    }

    /** @return array{float, string}|null */
    private function baseQuantity(float $quantity, string $unit): ?array
    {
        return match ($unit) {
            'g', 'gram', 'grams' => [$quantity, 'mass'],
            'kg', 'kilogram', 'kilograms' => [$quantity * 1000, 'mass'],
            'ml', 'millilitre', 'millilitres' => [$quantity, 'volume'],
            'l', 'litre', 'litres' => [$quantity * 1000, 'volume'],
            default => null,
        };
    }

    private function isEachUnit(string $unit): bool
    {
        return in_array($unit, ['each', 'ea'], true);
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
