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

export type ShoppingListItemCategory =
    | 'fruit_veg'
    | 'meat_seafood'
    | 'dairy_eggs'
    | 'bakery'
    | 'pantry'
    | 'frozen'
    | 'drinks'
    | 'household'
    | 'other';

export type ShoppingListItem = {
    id: number;
    source_kind: 'recipe' | 'planned_meal' | 'manual' | 'staple';
    category: ShoppingListItemCategory;
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
    preparation_id: number | null;
    preparation_status: 'not_started' | 'pending' | 'processing' | 'failed';
    failure_message: string | null;
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
    recipe_preparation: {
        required: number;
        ready: number;
        preparing: number;
        failed: number;
        unresolved: number;
    };
    conversation: {
        id: number;
        messages: Message[];
        feedback: ConversationFeedback[];
    };
    shopping_categories: {
        value: ShoppingListItemCategory;
        label: string;
    }[];
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
    automation: AutomationWorkspace;
};

export type AutomationConnection = {
    uuid: string;
    name: string | null;
    status: 'pending' | 'active';
    paired_at: string | null;
    last_seen_at: string | null;
    expires_at: string;
};

export type AutomationRunStatus =
    | 'awaiting_browser'
    | 'queued'
    | 'processing'
    | 'executing'
    | 'awaiting_approval'
    | 'paused'
    | 'takeover'
    | 'awaiting_review'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'expired';

export type AutomationRun = {
    uuid: string;
    status: AutomationRunStatus;
    shopping_list_revision: number;
    retailer: { id: number; name: string; slug: string };
    browser_connection: {
        uuid: string;
        name: string | null;
        status: string;
        last_seen_at: string | null;
    };
    progress: { total: number; added: number; unresolved: number };
    pause_reason: string | null;
    error_message: string | null;
    current_url: string | null;
    expires_at: string;
    started_at: string | null;
    finished_at: string | null;
    approvals: {
        id: number;
        risk_kind: string;
        proposed_action: string;
        consequence: string;
        status: 'pending' | 'approved' | 'rejected' | 'expired';
        expires_at: string;
    }[];
    steps: {
        id: number;
        sequence: number;
        status: string;
        action_count: number;
        error_message: string | null;
        requested_at: string | null;
        executed_at: string | null;
    }[];
    reconciliations: {
        id: number;
        shopping_list_item_id: number | null;
        status:
            'matched' | 'substituted' | 'unresolved' | 'unavailable' | 'extra';
        intended_name: string;
        product_name: string | null;
        brand: string | null;
        pack: string | null;
        quantity: number | null;
        unit_price: number | null;
        total_price: number | null;
        substitution_reason: string | null;
    }[];
};

export type AutomationWorkspace = {
    pairing_code: string | null;
    connections: AutomationConnection[];
    runs: AutomationRun[];
};
import type {
    ConversationFeedback,
    Message,
} from '@/features/meal-plans/types';
