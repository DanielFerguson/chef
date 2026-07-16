export type UserSummary = {
    id: number;
    name: string;
};

export type Message = {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    author?: UserSummary | null;
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
    };
    conversation: {
        id: number;
        messages: Message[];
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
};

export type StreamEvent = {
    type: 'delta' | 'complete' | 'persisted' | 'error';
    delta?: string;
    message?: string;
};
