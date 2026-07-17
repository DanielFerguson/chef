<?php

namespace App\Automation;

use App\Models\Retailer;
use Illuminate\Validation\ValidationException;

class RetailerOriginPolicy
{
    /** @return string[] */
    public function allOrigins(): array
    {
        return ['https://www.woolworths.com.au', 'https://www.coles.com.au'];
    }

    public function originFor(Retailer $retailer): string
    {
        return match ($retailer->slug) {
            'woolworths' => 'https://www.woolworths.com.au',
            'coles' => 'https://www.coles.com.au',
            default => throw ValidationException::withMessages(['retailer' => 'Chef can prepare carts only for Woolworths or Coles.']),
        };
    }

    public function assertAllowed(Retailer $retailer, string $url): void
    {
        $origin = $this->origin($url);

        if ($origin !== $this->originFor($retailer)) {
            throw ValidationException::withMessages(['current_url' => 'Select a tab on the chosen retailer website.']);
        }
    }

    public function requiresTakeover(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, 'checkout')
            || str_contains($path, 'payment')
            || str_contains($path, 'login')
            || str_contains($path, 'sign-in')
            || str_contains($path, 'address')
            || str_contains($path, 'delivery-slot');
    }

    private function origin(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '') {
            return '';
        }

        return $scheme.'://'.$host;
    }
}
