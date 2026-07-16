export type UserSummary = {
    id: number;
    name: string;
};

export type Message = {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    author?: UserSummary | null;
    feedback?: ConversationFeedback[];
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
        pending_proposals: number;
        slots_without_participants: number;
        recipes_required: number;
        recipes_ready: number;
        recipes_preparing: number;
        recipes_failed: number;
        recipes_unresolved: number;
        ready_for_confirmation: boolean;
        confirmed: boolean;
        next_action:
            | 'fill_open_slots'
            | 'resolve_proposals'
            | 'confirm_participants'
            | 'prepare_recipes'
            | 'wait_for_recipes'
            | 'retry_recipes'
            | 'review_and_confirm'
            | 'begin_shopping'
            | 'continue_planning';
    };
};

export type StreamEvent = {
    type: 'delta' | 'complete' | 'persisted' | 'error';
    delta?: string;
    message?: string;
};
