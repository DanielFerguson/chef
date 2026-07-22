export type UserSummary = {
    id: number;
    name: string;
};

export type Message = {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    created_at?: string | null;
    author?: UserSummary | null;
    feedback?: ConversationFeedback[];
    client_message_id?: string | null;
    response_status?: 'pending' | 'processing' | 'completed' | 'failed' | null;
    response_error?: string | null;
};

export type ConversationFeedback = {
    id: number;
    context: 'assistant_message' | 'planning_confirmed';
    rating: 'helpful' | 'unhelpful';
    reasons: string[] | null;
    comment: string | null;
};

export type Preference = {
    id: number;
    person_id: number | null;
    source_message_id?: number | null;
    evidence_quote?: string | null;
    source_message?: {
        id: number;
        conversation_id: number;
    } | null;
    subject: string;
    sentiment: 'like' | 'dislike';
    strength: number;
    provenance: 'stated' | 'default' | 'inferred' | 'feedback';
};

export type Constraint = {
    id: number;
    person_id: number | null;
    kind: string;
    subject: string;
    details: string | null;
    severity: string | null;
    confirmation_message?: {
        id: number;
        conversation_id: number;
        content: string;
        author?: UserSummary | null;
    } | null;
};

export type Person = {
    id: number;
    name: string;
    user_link?: { id: number; user_id: number } | null;
    preferences: Preference[];
    constraints: Constraint[];
    pivot?: { servings: number };
};

export type PlannedMeal = {
    id: number;
    meal_slot_id: number;
    title: string;
    summary: string | null;
    estimated_minutes: number | null;
    estimated_cost: number | null;
    type:
        'recipe' | 'custom' | 'leftovers' | 'takeaway' | 'eating_out' | 'open';
    status: 'planned' | 'skipped';
    servings: number;
    notes: string | null;
    recipe_version_id: number | null;
    source_planned_meal_id: number | null;
    recommendation_explanation: {
        safety: string;
        preferences: { subject: string; sentiment: string }[];
        recency: string;
        effort: string;
        cost: string;
    } | null;
    recipe_version: {
        id: number;
        recipe_id: number;
        version: number;
        title: string;
        summary: string | null;
    } | null;
};

export type MealSlot = {
    id: number;
    date: string;
    kind: string;
    label: string | null;
    participants: Person[];
    planned_meal: PlannedMeal | null;
};

export type MealProposal = {
    id: number;
    meal_slot_id: number | null;
    title: string;
    summary: string | null;
    estimated_minutes: number | null;
    estimated_cost: number | null;
    status: 'pending' | 'accepted' | 'rejected' | 'replaced';
};

export type MealPlanWorkspace = {
    plan: {
        id: number;
        title: string;
        starts_on: string;
        ends_on: string;
        revision: number;
        planning_confirmed_at: string | null;
        shopping_approved_at: string | null;
        safety_reviewed_at: string | null;
        safety_reviewed_context_hash: string | null;
        derived_data_stale_at: string | null;
        derived_data_stale_reason: string | null;
        slots: MealSlot[];
        proposals: MealProposal[];
        revisions: {
            id: number;
            revision: number;
            summary: string;
            created_at: string;
        }[];
        milestones: {
            id: number;
            kind: string;
            plan_revision: number;
            achieved_at: string;
        }[];
        shopping_list: {
            id: number;
            status: 'draft' | 'completed';
            generation_status: 'pending' | 'processing' | 'ready' | 'failed';
            revision: number;
            stale_at: string | null;
        } | null;
    };
    conversation: {
        id: number;
        messages: Message[];
        feedback: ConversationFeedback[];
    };
    household: {
        id: number;
        name: string;
        timezone: string;
        people: Person[];
        preferences: Preference[];
        constraints: Constraint[];
    };
    recipes: {
        id: number;
        title: string;
        summary: string | null;
        latest_version: {
            id: number;
            version: number;
            title: string;
            summary: string | null;
        };
    }[];
    readiness: {
        total_slots: number;
        filled_slots: number;
        open_slots: number;
        uncovered_slots: number;
        pending_proposals: number;
        slots_without_participants: number;
        recipes_required: number;
        recipes_ready: number;
        recipes_preparing: number;
        recipes_failed: number;
        recipes_unresolved: number;
        ready_for_approval: boolean;
        ready_for_safety_review: boolean;
        safety_reviewed: boolean;
        safety_review_required: boolean;
        ready_for_safety_confirmation: boolean;
        ready_for_confirmation: boolean;
        confirmed: boolean;
        next_action:
            | 'fill_open_slots'
            | 'resolve_proposals'
            | 'confirm_participants'
            | 'prepare_recipes'
            | 'wait_for_recipes'
            | 'retry_recipes'
            | 'review_safety'
            | 'review_and_approve'
            | 'review_and_confirm'
            | 'begin_shopping'
            | 'continue_planning';
    };
};

export type StreamEvent = {
    type: 'delta' | 'complete' | 'persisted' | 'error';
    code?:
        | 'rate_limited'
        | 'provider_overloaded'
        | 'insufficient_credits'
        | 'configuration_error'
        | 'provider_error'
        | 'tool_error'
        | 'empty_response'
        | 'unknown';
    delta?: string;
    message?: string;
    retryable?: boolean;
};
