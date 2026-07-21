<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\RetailerProductDiscovery;
use App\Models\Retailer;
use Illuminate\Support\Str;

class FakeRetailerProductDiscovery implements RetailerProductDiscovery
{
    public bool $returnAmbiguousCandidates = false;

    public bool $returnNoCandidates = false;

    public int $calls = 0;

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $candidatesByName = [];

    public function discover(Retailer $retailer, array $requirements): array
    {
        $this->calls++;
        $results = [];

        foreach ($requirements as $index => $requirement) {
            $name = (string) $requirement['name'];

            if (array_key_exists($name, $this->candidatesByName)) {
                $results[$index] = $this->candidatesByName[$name];

                continue;
            }

            if ($this->returnNoCandidates) {
                $results[$index] = [];

                continue;
            }

            $externalId = substr(hash('sha256', Str::lower($name)), 0, 10);
            $results[$index] = [[
                'external_id' => $externalId,
                'product_name' => $name,
                'product_url' => 'https://www.woolworths.com.au/shop/productdetails/'.$externalId,
                'price' => 3.5,
                'pack_size' => $requirement['unit'],
                'in_stock' => true,
                'confidence' => 0.99,
                'source' => 'fake_public_catalogue',
            ]];

            if ($this->returnAmbiguousCandidates) {
                $results[$index][] = [
                    'external_id' => $externalId.'-alt',
                    'product_name' => $name.' alternative',
                    'product_url' => 'https://www.woolworths.com.au/shop/productdetails/'.$externalId.'-alt',
                    'price' => 3.75,
                    'pack_size' => $requirement['unit'],
                    'in_stock' => true,
                    'confidence' => 0.96,
                    'source' => 'fake_public_catalogue',
                ];
            }
        }

        return $results;
    }
}
