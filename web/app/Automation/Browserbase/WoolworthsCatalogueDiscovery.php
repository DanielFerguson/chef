<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\RetailerProductDiscovery;
use App\Models\Retailer;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class WoolworthsCatalogueDiscovery implements RetailerProductDiscovery
{
    public function discover(Retailer $retailer, array $requirements): array
    {
        if ($retailer->slug !== 'woolworths' || $requirements === []) {
            return [];
        }

        $concurrency = max(1, min(8, (int) config('services.woolworths.discovery_concurrency', 4)));
        $maxCandidates = max(1, min(12, (int) config('services.woolworths.discovery_max_candidates', 5)));
        $url = (string) config('services.woolworths.catalogue_search_url', 'https://www.woolworths.com.au/apis/ui/Search/products');
        $responses = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'Chef product-plan discovery'])
            ->connectTimeout(5)
            ->timeout(max(5, (int) config('services.woolworths.discovery_timeout', 12)))
            ->pool(function (Pool $pool) use ($requirements, $url, $maxCandidates): void {
                foreach ($requirements as $index => $requirement) {
                    $pool->as((string) $index)->get($url, [
                        'searchTerm' => $requirement['name'],
                        'pageNumber' => 1,
                        'pageSize' => $maxCandidates,
                        'sortType' => 'TraderRelevance',
                    ]);
                }
            }, $concurrency);
        $discovered = [];

        foreach ($requirements as $index => $requirement) {
            $response = $responses[(string) $index] ?? null;
            $discovered[$index] = $response instanceof Response && $response->successful()
                ? $this->candidates($response->json(), $requirement['name'], $maxCandidates)
                : [];
        }

        return $discovered;
    }

    /** @return array<int, array<string, mixed>> */
    private function candidates(mixed $payload, string $requirementName, int $limit): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $products = $this->collectProducts($payload);

        return collect($products)
            ->map(function (array $product) use ($requirementName): ?array {
                $externalId = $product['Stockcode'] ?? $product['StockCode'] ?? $product['ProductId'] ?? $product['Id'] ?? null;
                $name = $product['Name'] ?? $product['DisplayName'] ?? null;

                if ((! is_string($externalId) && ! is_numeric($externalId)) || ! is_string($name) || trim($name) === '') {
                    return null;
                }

                $externalId = (string) $externalId;

                return [
                    'external_id' => $externalId,
                    'product_name' => Str::squish($name),
                    'product_url' => 'https://www.woolworths.com.au/shop/productdetails/'.rawurlencode($externalId),
                    'price' => $this->numeric($product['Price'] ?? $product['CupPrice'] ?? null),
                    'pack_size' => is_string($product['CupString'] ?? null)
                        ? Str::squish($product['CupString'])
                        : (is_string($product['PackageSize'] ?? null) ? Str::squish($product['PackageSize']) : null),
                    'in_stock' => ! isset($product['IsInStock']) || (bool) $product['IsInStock'],
                    'confidence' => $this->nameConfidence($requirementName, $name),
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
        $requiredTokens = $this->tokens($requirement);
        $candidateTokens = $this->tokens($candidate);

        if ($requiredTokens === [] || $candidateTokens === []) {
            return 0;
        }

        $overlap = count(array_intersect($requiredTokens, $candidateTokens));

        return round((($overlap / count($requiredTokens)) * 0.8) + (($overlap / count($candidateTokens)) * 0.2), 4);
    }

    /** @return array<int, string> */
    private function tokens(string $value): array
    {
        try {
            $value = Str::of($value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
        } catch (Throwable) {
            return [];
        }

        return collect(explode(' ', $value))->filter(fn (string $token) => strlen($token) > 1)->unique()->values()->all();
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
