<?php

namespace App\Enums;

enum BasketRunStatus: string
{
    case WaitingForRecipes = 'waiting_for_recipes';
    case WaitingForConnection = 'waiting_for_connection';
    case BuildingRequirements = 'building_requirements';
    case DiscoveringProducts = 'discovering_products';
    case SelectingProducts = 'selecting_products';
    case PreparingResolution = 'preparing_resolution';
    case NeedsPlanReview = 'needs_plan_review';
    case RevalidatingProducts = 'revalidating_products';
    case ProductsSelected = 'products_selected';
    case ReplacingBasket = 'replacing_basket';
    case Ready = 'ready';
    case NeedsProduct = 'needs_product';
    case ReauthenticationRequired = 'reauthentication_required';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case NeedsAttention = 'needs_attention';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Ready,
            self::ProductsSelected,
            self::NeedsProduct,
            self::NeedsPlanReview,
            self::ReauthenticationRequired,
            self::Failed,
            self::Uncertain,
            self::Restored,
            self::NeedsAttention,
            self::Cancelled,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
