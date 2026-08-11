<?php

namespace App\Models;

use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\RetailerPurchasePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RetailerProvider $provider
 * @property RetailerHomeBrandPreference $home_brand_preference
 * @property RetailerBulkPreference $bulk_preference
 * @property RetailerOrganicPreference $organic_preference
 * @property list<string> $preferred_brands
 * @property int|null $default_basket_target_cents
 */
#[Fillable(['team_id', 'updated_by_user_id', 'provider', 'home_brand_preference', 'bulk_preference', 'organic_preference', 'preferred_brands', 'default_basket_target_cents'])]
class RetailerPurchasePolicy extends Model
{
    /** @use HasFactory<RetailerPurchasePolicyFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => RetailerProvider::class,
            'home_brand_preference' => RetailerHomeBrandPreference::class,
            'bulk_preference' => RetailerBulkPreference::class,
            'organic_preference' => RetailerOrganicPreference::class,
            'preferred_brands' => 'array',
        ];
    }
}
