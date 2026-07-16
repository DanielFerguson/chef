export type ShoppingPlan = {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
    revision: number;
    planning_confirmed_at: string;
};

export type ShoppingListItemSource = {
    id: number;
    quantity: number | null;
    unit: string | null;
    planned_meal: {
        id: number;
        title: string;
        meal_slot: {
            id: number;
            date: string;
            kind: string;
        };
    } | null;
};

export type ShoppingListItem = {
    id: number;
    source_kind: 'recipe' | 'planned_meal' | 'manual' | 'staple';
    name: string;
    normalized_name: string;
    quantity: number | null;
    unit: string | null;
    note: string | null;
    included: boolean;
    in_pantry: boolean;
    checked: boolean;
    optional: boolean;
    estimated_price: number | null;
    position: number;
    sources: ShoppingListItemSource[];
    product_match: {
        id: number;
        pack_count: number;
        estimated_total: number;
        preferred: boolean;
        retail_product: {
            id: number;
            name: string;
            brand: string | null;
            pack_quantity: number | null;
            pack_unit: string | null;
            current_price: number | null;
            retailer: { id: number; name: string; slug: string };
        };
    } | null;
};

export type ShoppingList = {
    id: number;
    revision: number;
    source_plan_revision: number;
    status: 'draft' | 'completed';
    completed_at: string | null;
    stale_at: string | null;
    stale_reason: string | null;
    stale_diff: {
        from_plan_revision: number;
        to_plan_revision: number;
        changes: { revision: number; summary: string; details: unknown }[];
    } | null;
    items: ShoppingListItem[];
    revisions: {
        id: number;
        revision: number;
        summary: string;
        created_at: string;
    }[];
    orders: {
        id: number;
        actual_total: number;
        estimated_total: number | null;
        currency: string;
        recorded_at: string;
        retailer: { id: number; name: string } | null;
    }[];
};

export type MissingMeal = {
    id: number;
    title: string;
    date: string;
    kind: string;
};

export type ProductPreference = {
    id: number;
    retailer_id: number | null;
    normalized_item_name: string | null;
    preferred_brand: string | null;
    preferred_pack: string | null;
    accept_substitutes: boolean;
    maximum_price: number | null;
    note: string | null;
    retailer: { id: number; name: string; slug: string } | null;
};

export type ShoppingWorkspace = {
    plan: ShoppingPlan;
    shopping_list: ShoppingList | null;
    missing_meals: MissingMeal[];
    retailers: { id: number; name: string; slug: string }[];
    product_preferences: ProductPreference[];
    budget: {
        household_default: number | null;
        plan_override: number | null;
        effective: number | null;
        projected_total: number;
        unmatched_items: number;
        currency: string;
    };
};
