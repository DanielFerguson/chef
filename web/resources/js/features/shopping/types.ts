export type ShoppingPlan = {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
    revision: number;
    planning_confirmed_at: string;
    shopping_approved_at: string | null;
    safety_review_required: boolean;
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
    source_kind:
        'recipe' | 'plan_generated' | 'planned_meal' | 'manual' | 'staple';
    category: ShoppingListItemCategory;
    name: string;
    normalized_name: string;
    quantity: number | null;
    unit: string | null;
    note: string | null;
    included: boolean;
    in_pantry: boolean;
    checked: boolean;
    ordered_at: string | null;
    ordered_via_cart_snapshot_id: number | null;
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
            external_id: string | null;
            product_url: string | null;
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
    fulfilment_method: 'delivery' | 'pickup' | null;
    fulfilment_scheduled_for: string | null;
    fulfilment_confirmed_at: string | null;
    generation_status: 'pending' | 'processing' | 'ready' | 'failed';
    generation_attempts: number;
    last_generation_method: 'one_shot' | 'deterministic_fallback' | null;
    generation_failure_code: string | null;
    generation_failure_message: string | null;
    generation_started_at: string | null;
    generation_completed_at: string | null;
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
        cart_snapshot_id: number | null;
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

export type AutomationRunItem = {
    id: number;
    position: number;
    name: string;
    quantity: number | null;
    unit: string | null;
    status:
        | 'pending'
        | 'searching'
        | 'matched'
        | 'substituted'
        | 'unavailable'
        | 'skipped'
        | 'awaiting_decision'
        | 'failed';
    product: {
        external_product_id: string | null;
        product_name: string;
        quantity: number | null;
        unit: string | null;
        unit_price: number | null;
        total_price: number | null;
    } | null;
    failure_message: string | null;
};

export type CartSnapshotLine = {
    id: number;
    classification:
        | 'matched'
        | 'substituted'
        | 'unavailable'
        | 'quantity_adjusted'
        | 'price_changed'
        | 'pre_existing'
        | 'unresolved';
    product_name: string;
    quantity: number | string | null;
    unit: string | null;
    unit_price: number | string | null;
    total_price: number | string | null;
    pre_existing: boolean;
    metadata: Record<string, unknown> | null;
};

export type ObservedCartLine = {
    external_product_id: string | null;
    product_name: string;
    quantity: number | string | null;
    unit: string | null;
    unit_price: number | string | null;
    total_price: number | string | null;
};

export type AutomationRun = {
    id: number;
    status:
        | 'checking_connection'
        | 'awaiting_reauthentication'
        | 'inspecting_existing_cart'
        | 'awaiting_existing_cart_decision'
        | 'queued'
        | 'running'
        | 'awaiting_item_decision'
        | 'reconciling'
        | 'ready_for_review'
        | 'superseded'
        | 'cancelled'
        | 'failed'
        | 'expired';
    shopping_list_revision: number;
    shopping_list_revision_id: number;
    current_shopping_list_revision: number;
    revision_diverged: boolean;
    existing_cart_decision: 'merge' | 'replace' | 'cancel' | null;
    started_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    failure_message: string | null;
    progress: {
        resolved: number;
        total: number;
        actions_taken: number;
        max_actions: number | null;
    };
    items: AutomationRunItem[];
    intervention: {
        id: number;
        type:
            | 'reauthentication'
            | 'existing_cart'
            | 'item_decision'
            | 'price_limit'
            | 'substitution'
            | 'bot_detection'
            | 'sensitive_screen'
            | 'cart_changed'
            | 'manual_takeover';
        payload: {
            message?: string;
            lines?: ObservedCartLine[];
            cart_total?: number | null;
            currency?: string;
            product?: Record<string, unknown> | null;
        } | null;
        requested_at: string;
        automation_run_item_id: number | null;
        takeover_url: string | null;
    } | null;
    snapshot: {
        id: number;
        currency: string;
        chef_subtotal: number | string | null;
        cart_total: number | string | null;
        captured_at: string;
        lines: CartSnapshotLine[];
    } | null;
    can_open_woolworths_cart: boolean;
    open_woolworths_cart_url: string | null;
    normal_app_sync_proven: boolean;
};

export type RetailerOrderRunItem = {
    id: number;
    position: number;
    name: string;
    quantity: number | null;
    unit: string | null;
    status:
        | 'pending'
        | 'searching'
        | 'matched'
        | 'substituted'
        | 'unavailable'
        | 'skipped'
        | 'awaiting_decision'
        | 'failed';
    product: Record<string, unknown> | null;
    failure_message: string | null;
};

export type RetailerOrderRun = {
    id: number;
    status:
        | 'draft'
        | 'preparing_cart'
        | 'awaiting_cart_decision'
        | 'awaiting_item_decision'
        | 'awaiting_reauthentication'
        | 'cart_ready'
        | 'fetching_fulfilment_options'
        | 'awaiting_fulfilment_selection'
        | 'awaiting_order_confirmation'
        | 'submitting_order'
        | 'placed'
        | 'awaiting_placement_verification'
        | 'failed'
        | 'cancelled';
    shopping_list_revision_id: number;
    current_shopping_list_revision: number;
    existing_cart_decision: 'merge' | 'replace' | 'cancel' | null;
    fulfilment_type: 'delivery' | 'pickup' | null;
    fulfilment_options: {
        type?: 'delivery' | 'pickup';
        slots?: {
            id: string;
            label?: string;
            starts_at?: string | null;
            ends_at?: string | null;
            fee?: number | null;
        }[];
    } | null;
    fulfilment_options_expires_at: string | null;
    selected_slot: {
        id: string;
        label?: string;
        starts_at?: string | null;
        ends_at?: string | null;
        fee?: number | null;
    } | null;
    confirmation: {
        fulfilment_type: string | null;
        selected_slot: Record<string, unknown> | null;
        fingerprint: string;
        consequence: string;
    } | null;
    cart_checksum: string | null;
    retailer_order_reference: string | null;
    failure_message: string | null;
    expires_at: string | null;
    cart_decision_needed: boolean;
    placement_verification_needed: boolean;
    progress: {
        resolved: number;
        total: number;
    };
    items: RetailerOrderRunItem[];
    can_open_woolworths_cart: boolean;
    open_woolworths_cart_url: string | null;
};

export type CartAutomation = {
    approved: boolean;
    connection_enabled: boolean;
    cart_mutation_enabled: boolean;
    normal_app_sync_proven: boolean;
    ready: boolean;
    readiness_reasons: string[];
    shopping_list_revision_id: number | null;
    preflight: {
        total_items: number;
        matched_items: number;
        automatic_search_items: number;
        automatic_search_item_names: string[];
        requires_exact_matches: boolean;
        can_prepare: boolean;
        constraints: {
            id: number;
            kind: string;
            subject: string;
            severity: string | null;
            person: string | null;
        }[];
    };
    product_plan: {
        id: number;
        status: 'needs_review' | 'ready' | 'frozen' | 'superseded' | 'failed';
        exact_items: number;
        ambiguous_items: number;
        unresolved_items: number;
        discovery_failed: boolean;
        discovery_ms: number | null;
        items: {
            id: number;
            shopping_list_item_id: number | null;
            name: string;
            status: 'exact' | 'ambiguous' | 'unresolved';
            decision_reason: string | null;
            selected_product: Record<string, unknown> | null;
            candidates: Record<string, unknown>[];
        }[];
    } | null;
    connection: {
        id: number;
        status:
            | 'pending_login'
            | 'checking'
            | 'connected'
            | 'reauthentication_required'
            | 'disconnected'
            | 'revoked'
            | 'error';
        owner_user_id: number;
        last_verified_at: string | null;
    } | null;
    run: AutomationRun | null;
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
    cart_automation: CartAutomation;
    retailer_order_run: RetailerOrderRun | null;
};
import type {
    ConversationFeedback,
    Message,
} from '@/features/meal-plans/types';
