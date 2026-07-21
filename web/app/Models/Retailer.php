<?php

namespace App\Models;

use Database\Factories\RetailerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $website_url
 * @property bool $active
 */
#[Fillable(['name', 'slug', 'website_url', 'active'])]
class Retailer extends Model
{
    /** @use HasFactory<RetailerFactory> */
    use HasFactory;

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
