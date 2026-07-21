<?php

namespace App\Automation\Contracts;

use App\Models\Retailer;

interface RetailerProductDiscovery
{
    /**
     * @param  array<int, array{id: int|null, name: string, quantity: float|null, unit: string|null}>  $requirements
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function discover(Retailer $retailer, array $requirements): array;
}
