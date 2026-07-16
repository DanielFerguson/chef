export type RecipeIngredient = {
    id: number;
    name: string;
    quantity: number | null;
    unit: string | null;
    preparation: string | null;
    optional: boolean;
};

export type RecipeStep = {
    id: number;
    instruction: string;
    timer_minutes: number | null;
};

export type RecipeNotice = {
    id: number;
    kind: string;
    instruction: string;
    lead_minutes: number | null;
};

export type RecipeVersion = {
    id: number;
    version: number;
    title: string;
    summary: string | null;
    servings: number;
    prep_minutes: number | null;
    cook_minutes: number | null;
    source_url: string | null;
    notes: string | null;
    published_at: string;
    ingredients: RecipeIngredient[];
    steps: RecipeStep[];
    equipment: { id: number; name: string }[];
    preparation_notices: RecipeNotice[];
};

export type Recipe = {
    id: number;
    title: string;
    summary: string | null;
    source_url: string | null;
    latest_version?: RecipeVersion;
    versions?: RecipeVersion[];
};
