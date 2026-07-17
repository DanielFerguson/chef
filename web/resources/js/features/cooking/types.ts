import type { RecipeVersion } from '@/features/recipes/types';

export type CookingPerson = {
    id: number;
    name: string;
};

export type MealFeedback = {
    id: number;
    person_id: number;
    rating: 'dislike' | 'neutral' | 'like' | 'favourite';
    portion: string | null;
    effort: string | null;
    cost: string | null;
    leftovers: string | null;
    notes: string | null;
    recipe_adjustment: string | null;
    person: CookingPerson;
};

export type MealOutcomeStatus =
    'cooked' | 'skipped' | 'postponed' | 'replaced' | 'leftovers' | 'ate_out';

export type MealOutcome = {
    id: number;
    status: MealOutcomeStatus | null;
    current_step_position: number;
    replacement_title: string | null;
    postponed_until: string | null;
    leftover_servings: number | null;
    notes: string | null;
    started_at: string | null;
    completed_at: string | null;
    feedback: MealFeedback[];
};

export type CookingMeal = {
    id: number;
    title: string;
    type: string;
    servings: number;
    meal_plan: {
        id: number;
        title: string;
        starts_on: string;
        ends_on: string;
    };
    meal_slot: {
        id: number;
        date: string;
        kind: string;
        label: string | null;
        participants: CookingPerson[];
    };
    recipe_version:
        | (RecipeVersion & {
              recipe: { id: number; title: string };
          })
        | null;
    outcome: MealOutcome | null;
};

export type ShoppingChoice = {
    ingredient: string;
    product: string;
    brand: string | null;
    substituted_from: string | null;
    retailer: string | null;
};
