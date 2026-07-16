<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'website_url', 'active'])]
class Retailer extends Model
{
    /** @return HasMany<RetailProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(RetailProduct::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
