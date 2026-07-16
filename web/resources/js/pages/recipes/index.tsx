import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, FileInput, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Recipe } from '@/features/recipes/types';

const starterRecipe = {
    title: '',
    summary: '',
    servings: 2,
    prep_minutes: 15,
    cook_minutes: 30,
    ingredients: [{ name: '', quantity: '', unit: '' }],
    steps: [{ instruction: '', timer_minutes: '' }],
    equipment: [] as string[],
    notices: [] as {
        kind: string;
        instruction: string;
        lead_minutes: number | null;
    }[],
};

function CreateRecipeForm() {
    const form = useForm(starterRecipe);

    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/recipes');
            }}
        >
            <div className="grid gap-3 sm:grid-cols-[1fr_7rem]">
                <Input
                    aria-label="Recipe title"
                    placeholder="Recipe title"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    required
                />
                <Input
                    aria-label="Servings"
                    type="number"
                    min="0.25"
                    step="0.25"
                    value={form.data.servings}
                    onChange={(event) =>
                        form.setData('servings', Number(event.target.value))
                    }
                    required
                />
            </div>
            <textarea
                aria-label="Recipe summary"
                className="min-h-20 w-full rounded-md border bg-background px-3 py-2 text-sm"
                placeholder="A short description"
                value={form.data.summary}
                onChange={(event) =>
                    form.setData('summary', event.target.value)
                }
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <Input
                    aria-label="Preparation minutes"
                    type="number"
                    min="0"
                    placeholder="Prep minutes"
                    value={form.data.prep_minutes}
                    onChange={(event) =>
                        form.setData('prep_minutes', Number(event.target.value))
                    }
                />
                <Input
                    aria-label="Cooking minutes"
                    type="number"
                    min="0"
                    placeholder="Cook minutes"
                    value={form.data.cook_minutes}
                    onChange={(event) =>
                        form.setData('cook_minutes', Number(event.target.value))
                    }
                />
            </div>
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Ingredients</legend>
                {form.data.ingredients.map((ingredient, index) => (
                    <div
                        key={index}
                        className="grid grid-cols-[1fr_5rem_5rem] gap-2"
                    >
                        <Input
                            aria-label={`Ingredient ${index + 1}`}
                            placeholder="Chicken breast"
                            value={ingredient.name}
                            onChange={(event) => {
                                const ingredients = [...form.data.ingredients];
                                ingredients[index] = {
                                    ...ingredient,
                                    name: event.target.value,
                                };
                                form.setData('ingredients', ingredients);
                            }}
                            required
                        />
                        <Input
                            aria-label={`Ingredient ${index + 1} quantity`}
                            placeholder="500"
                            type="number"
                            min="0"
                            step="any"
                            value={ingredient.quantity}
                            onChange={(event) => {
                                const ingredients = [...form.data.ingredients];
                                ingredients[index] = {
                                    ...ingredient,
                                    quantity: event.target.value,
                                };
                                form.setData('ingredients', ingredients);
                            }}
                        />
                        <Input
                            aria-label={`Ingredient ${index + 1} unit`}
                            placeholder="g"
                            value={ingredient.unit}
                            onChange={(event) => {
                                const ingredients = [...form.data.ingredients];
                                ingredients[index] = {
                                    ...ingredient,
                                    unit: event.target.value,
                                };
                                form.setData('ingredients', ingredients);
                            }}
                        />
                    </div>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        form.setData('ingredients', [
                            ...form.data.ingredients,
                            { name: '', quantity: '', unit: '' },
                        ])
                    }
                >
                    <Plus /> Ingredient
                </Button>
            </fieldset>
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Steps</legend>
                {form.data.steps.map((step, index) => (
                    <textarea
                        key={index}
                        aria-label={`Step ${index + 1}`}
                        className="min-h-16 w-full rounded-md border bg-background px-3 py-2 text-sm"
                        placeholder={`${index + 1}. What happens next?`}
                        value={step.instruction}
                        onChange={(event) => {
                            const steps = [...form.data.steps];
                            steps[index] = {
                                ...step,
                                instruction: event.target.value,
                            };
                            form.setData('steps', steps);
                        }}
                        required
                    />
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        form.setData('steps', [
                            ...form.data.steps,
                            { instruction: '', timer_minutes: '' },
                        ])
                    }
                >
                    <Plus /> Step
                </Button>
            </fieldset>
            {Object.keys(form.errors).length > 0 && (
                <p role="alert" className="text-sm text-destructive">
                    Check the recipe fields and try again.
                </p>
            )}
            <Button disabled={form.processing} type="submit">
                Save recipe
            </Button>
        </form>
    );
}

function ImportRecipeForm() {
    const form = useForm({ source_text: '', source_url: '' });

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/recipes/import');
            }}
        >
            <p className="text-sm leading-6 text-muted-foreground">
                Paste a title followed by Ingredients and Steps headings. Chef
                will keep the imported text as structured, editable data.
            </p>
            <textarea
                aria-label="Recipe text to import"
                className="min-h-72 w-full rounded-md border bg-background px-3 py-2 font-mono text-sm"
                placeholder={
                    'Pork Katsu\n\nIngredients\n- Pork loin\n\nSteps\n1. Crumb the pork'
                }
                value={form.data.source_text}
                onChange={(event) =>
                    form.setData('source_text', event.target.value)
                }
                required
            />
            <Input
                aria-label="Recipe source URL"
                type="url"
                placeholder="Source URL (optional)"
                value={form.data.source_url}
                onChange={(event) =>
                    form.setData('source_url', event.target.value)
                }
            />
            {form.errors.source_text && (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.source_text}
                </p>
            )}
            <Button disabled={form.processing} type="submit">
                Import recipe
            </Button>
        </form>
    );
}

export default function RecipeIndex({ recipes }: { recipes: Recipe[] }) {
    const [mode, setMode] = useState<'library' | 'create' | 'import'>(
        'library',
    );

    return (
        <>
            <Head title="Recipes" />
            <main className="min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto w-full max-w-4xl px-5 py-8 sm:px-8 lg:py-12">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Family recipe library
                            </p>
                            <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                                Recipes
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Every edit creates a version, so planned meals
                                keep the recipe you chose.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                variant={
                                    mode === 'import' ? 'default' : 'outline'
                                }
                                onClick={() => setMode('import')}
                            >
                                <FileInput /> Import
                            </Button>
                            <Button onClick={() => setMode('create')}>
                                <Plus /> New recipe
                            </Button>
                        </div>
                    </header>

                    {mode === 'library' ? (
                        <section className="mt-8 divide-y border-y">
                            {recipes.length === 0 ? (
                                <div className="py-16 text-center">
                                    <BookOpen className="mx-auto size-6 text-muted-foreground" />
                                    <p className="mt-3 text-sm font-medium">
                                        No recipes yet
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Create one directly or paste a recipe
                                        you already use.
                                    </p>
                                </div>
                            ) : (
                                recipes.map((recipe) => (
                                    <Link
                                        key={recipe.id}
                                        href={`/recipes/${recipe.id}`}
                                        className="flex items-center justify-between gap-5 py-5 hover:text-primary"
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-medium">
                                                {recipe.title}
                                            </span>
                                            <span className="mt-1 block truncate text-sm text-muted-foreground">
                                                {recipe.summary ??
                                                    `${recipe.latest_version?.ingredients.length ?? 0} ingredients`}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            v
                                            {recipe.latest_version?.version ??
                                                1}
                                        </span>
                                    </Link>
                                ))
                            )}
                        </section>
                    ) : (
                        <section className="mt-8 max-w-2xl">
                            <button
                                type="button"
                                className="mb-5 text-sm text-muted-foreground hover:text-foreground"
                                onClick={() => setMode('library')}
                            >
                                ← Back to library
                            </button>
                            <h2 className="mb-5 text-lg font-semibold">
                                {mode === 'create'
                                    ? 'Create a recipe'
                                    : 'Import a recipe'}
                            </h2>
                            {mode === 'create' ? (
                                <CreateRecipeForm />
                            ) : (
                                <ImportRecipeForm />
                            )}
                        </section>
                    )}
                </div>
            </main>
        </>
    );
}
