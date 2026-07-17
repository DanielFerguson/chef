<?php

namespace App\Models;

use App\Enums\AutomationReconciliationStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $automation_run_id
 * @property int|null $shopping_list_item_id
 * @property AutomationReconciliationStatus $status
 * @property string $intended_name
 * @property string|null $retailer_product_identifier
 * @property string|null $product_name
 * @property string|null $brand
 * @property string|null $pack
 * @property int|null $quantity
 * @property float|null $unit_price
 * @property float|null $total_price
 * @property string|null $substitution_reason
 * @property float|null $confidence
 * @property array<string, mixed>|null $raw_data
 */
#[Fillable(['team_id', 'automation_run_id', 'shopping_list_item_id', 'status', 'intended_name', 'retailer_product_identifier', 'product_name', 'brand', 'pack', 'quantity', 'unit_price', 'total_price', 'substitution_reason', 'confidence', 'raw_data'])]
class AutomationReconciliation extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<AutomationRun, $this> */
    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationReconciliationStatus::class,
            'unit_price' => 'float',
            'total_price' => 'float',
            'confidence' => 'float',
            'raw_data' => 'array',
        ];
    }
}
