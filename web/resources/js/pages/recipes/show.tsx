import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock3,
    ExternalLink,
    Pencil,
    Plus,
    Trash2,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Recipe } from '@/features/recipes/types';

function quantity(value: number | null, unit: string | null) {
    if (value === null) {
        return '';
    }

    return `${value}${unit ? ` ${unit}` : ''}`;
}

type EditableIngredient = {
    _key: string;
    name: string;
    quantity: string;
    unit: string;
    preparation: string;
    optional: boolean;
};

type EditableStep = {
    _key: string;
    instruction: string;
    timer_minutes: string;
};

function IngredientFields({
    ingredients,
    onChange,
}: {
    ingredients: EditableIngredient[];
    onChange: (ingredients: EditableIngredient[]) => void;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Ingredients</legend>
            {ingredients.map((ingredient, index) => (
                <div
                    key={ingredient._key}
                    className="grid grid-cols-[minmax(0,1fr)_5rem_5rem_auto] gap-2"
                >
                    <Input
                        aria-label={`Revised ingredient ${index + 1}`}
                        value={ingredient.name}
                        onChange={(event) => {
                            const next = [...ingredients];
                            next[index] = {
                                ...ingredient,
                                name: event.target.value,
                            };
                            onChange(next);
                        }}
                        required
                    />
                    <Input
                        aria-label={`Revised ingredient ${index + 1} quantity`}
                        type="number"
                        min="0"
                        step="any"
                        value={ingredient.quantity}
                        onChange={(event) => {
                            const next = [...ingredients];
                            next[index] = {
                                ...ingredient,
                                quantity: event.target.value,
                            };
                            onChange(next);
                        }}
                    />
                    <Input
                        aria-label={`Revised ingredient ${index + 1} unit`}
                        value={ingredient.unit}
                        onChange={(event) => {
                            const next = [...ingredients];
                            next[index] = {
                                ...ingredient,
                                unit: event.target.value,
                            };
                            onChange(next);
                        }}
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={`Remove ingredient ${index + 1}`}
                        disabled={ingredients.length === 1}
                        onClick={() =>
                            onChange(
                                ingredients.filter(
                                    (_, itemIndex) => itemIndex !== index,
                                ),
                            )
                        }
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                    onChange([
                        ...ingredients,
                        {
                            _key: crypto.randomUUID(),
                            name: '',
                            quantity: '',
                            unit: '',
                            preparation: '',
                            optional: false,
                        },
                    ])
                }
            >
                <Plus /> Ingredient
            </Button>
        </fieldset>
    );
}

function StepFields({
    steps,
    onChange,
}: {
    steps: EditableStep[];
    onChange: (steps: EditableStep[]) => void;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Method</legend>
            {steps.map((step, index) => (
                <div
                    key={step._key}
                    className="grid grid-cols-[minmax(0,1fr)_6rem_auto] gap-2"
                >
                    <textarea
                        aria-label={`Revised step ${index + 1}`}
                        className="min-h-16 rounded-md border bg-background px-3 py-2 text-sm"
                        value={step.instruction}
                        onChange={(event) => {
                            const next = [...steps];
                            next[index] = {
                                ...step,
                                instruction: event.target.value,
                            };
                            onChange(next);
                        }}
                        required
                    />
                    <Input
                        aria-label={`Revised step ${index + 1} timer`}
                        type="number"
                        min="0"
                        placeholder="Timer"
                        value={step.timer_minutes}
                        onChange={(event) => {
                            const next = [...steps];
                            next[index] = {
                                ...step,
                                timer_minutes: event.target.value,
                            };
                            onChange(next);
                        }}
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={`Remove step ${index + 1}`}
                        disabled={steps.length === 1}
                        onClick={() =>
                            onChange(
                                steps.filter(
                                    (_, itemIndex) => itemIndex !== index,
                                ),
                            )
                        }
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                    onChange([
                        ...steps,
                        {
                            _key: crypto.randomUUID(),
                            instruction: '',
                            timer_minutes: '',
                        },
                    ])
                }
            >
                <Plus /> Step
            </Button>
        </fieldset>
    );
}

function RecipeVersionForm({
    recipe,
    onSaved,
}: {
    recipe: Recipe;
    onSaved: () => void;
}) {
    const version = recipe.versions![0];
    const form = useForm({
        title: version.title,
        summary: version.summary ?? '',
        servings: version.servings,
        prep_minutes: version.prep_minutes ?? 0,
        cook_minutes: version.cook_minutes ?? 0,
        source_url: version.source_url ?? '',
        notes: version.notes ?? '',
        storage_guidance: version.storage_guidance ?? '',
        ingredients: version.ingredients.map((item) => ({
            _key: `ingredient-${item.id}`,
            name: item.name,
            quantity: item.quantity?.toString() ?? '',
            unit: item.unit ?? '',
            preparation: item.preparation ?? '',
            optional: item.optional,
        })),
        steps: version.steps.map((step) => ({
            _key: `step-${step.id}`,
            instruction: step.instruction,
            timer_minutes: step.timer_minutes?.toString() ?? '',
        })),
        equipment: version.equipment.map((item) => item.name),
        notices: version.preparation_notices.map((notice) => ({
            kind: notice.kind,
            instruction: notice.instruction,
            lead_minutes: notice.lead_minutes,
        })),
    });

    return (
        <form
            className="mt-7 space-y-6 border-t pt-7"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    summary: data.summary.trim() || null,
                    source_url: data.source_url.trim() || null,
                    notes: data.notes.trim() || null,
                    storage_guidance: data.storage_guidance.trim() || null,
                    ingredients: data.ingredients.map((item) => ({
                        name: item.name,
                        quantity:
                            item.quantity === '' ? null : Number(item.quantity),
                        unit: item.unit.trim() || null,
                        preparation: item.preparation.trim() || null,
                        optional: item.optional,
                    })),
                    steps: data.steps.map((step) => ({
                        instruction: step.instruction,
                        timer_minutes:
                            step.timer_minutes === ''
                                ? null
                                : Number(step.timer_minutes),
                    })),
                    equipment: data.equipment.filter((item) => item.trim()),
                }));
                form.post(`/recipes/${recipe.id}/versions`, {
                    preserveScroll: true,
                    onSuccess: onSaved,
                });
            }}
        >
            <div>
                <h2 className="text-lg font-semibold">
                    Create revised version
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Existing meal plans keep the exact recipe version they were
                    confirmed with.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_7rem]">
                <Input
                    aria-label="Revised recipe title"
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    required
                />
                <Input
                    aria-label="Revised recipe servings"
                    type="number"
                    min="0.25"
                    step="0.25"
                    value={form.data.servings}
                    onChange={(event) =>
                        form.setData('servings', Number(event.target.value))
                    }
                />
            </div>
            <textarea
                aria-label="Revised recipe summary"
                className="min-h-20 w-full rounded-md border bg-background px-3 py-2 text-sm"
                value={form.data.summary}
                onChange={(event) =>
                    form.setData('summary', event.target.value)
                }
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <Input
                    aria-label="Revised preparation minutes"
                    type="number"
                    min="0"
                    value={form.data.prep_minutes}
                    onChange={(event) =>
                        form.setData('prep_minutes', Number(event.target.value))
                    }
                />
                <Input
                    aria-label="Revised cooking minutes"
                    type="number"
                    min="0"
                    value={form.data.cook_minutes}
                    onChange={(event) =>
                        form.setData('cook_minutes', Number(event.target.value))
                    }
                />
            </div>
            <IngredientFields
                ingredients={form.data.ingredients}
                onChange={(ingredients) =>
                    form.setData('ingredients', ingredients)
                }
            />
            <StepFields
                steps={form.data.steps}
                onChange={(steps) => form.setData('steps', steps)}
            />
            <textarea
                aria-label="Storage and leftovers guidance"
                className="min-h-20 w-full rounded-md border bg-background px-3 py-2 text-sm"
                placeholder="How to cool, store, reheat, or use leftovers"
                value={form.data.storage_guidance}
                onChange={(event) =>
                    form.setData('storage_guidance', event.target.value)
                }
            />
            <textarea
                aria-label="Revised recipe notes"
                className="min-h-20 w-full rounded-md border bg-background px-3 py-2 text-sm"
                placeholder="Other recipe notes"
                value={form.data.notes}
                onChange={(event) => form.setData('notes', event.target.value)}
            />
            {Object.keys(form.errors).length > 0 && (
                <p role="alert" className="text-sm text-destructive">
                    Check the revised recipe fields and try again.
                </p>
            )}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" onClick={onSaved}>
                    Cancel
                </Button>
                <Button disabled={form.processing}>Save as new version</Button>
            </div>
        </form>
    );
}

export default function RecipeShow({ recipe }: { recipe: Recipe }) {
    const version = recipe.versions?.[0];
    const [editing, setEditing] = useState(false);

    if (!version) {
        return null;
    }

    return (
        <>
            <Head title={recipe.title} />
            <main className="min-w-0 flex-1 overflow-y-auto">
                <article className="mx-auto w-full max-w-3xl px-5 py-8 sm:px-8 lg:py-12">
                    <Link
                        href="/recipes"
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        ← Recipes
                    </Link>
                    <header className="mt-7 border-b pb-7">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-3xl font-semibold tracking-tight">
                                        {version.title}
                                    </h1>
                                    <Badge variant="secondary">
                                        v{version.version}
                                    </Badge>
                                </div>
                                {version.summary && (
                                    <p className="mt-3 leading-7 text-muted-foreground">
                                        {version.summary}
                                    </p>
                                )}
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setEditing((value) => !value)
                                    }
                                >
                                    <Pencil /> Revise recipe
                                </Button>
                                {version.source_url && (
                                    <a
                                        href={version.source_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-muted-foreground hover:text-foreground"
                                    >
                                        <ExternalLink className="size-4" />
                                        <span className="sr-only">
                                            Recipe source
                                        </span>
                                    </a>
                                )}
                            </div>
                        </div>
                        <div className="mt-5 flex flex-wrap gap-5 text-sm text-muted-foreground">
                            <span className="flex items-center gap-2">
                                <UsersRound className="size-4" />{' '}
                                {version.servings} servings
                            </span>
                            <span className="flex items-center gap-2">
                                <Clock3 className="size-4" />{' '}
                                {(version.prep_minutes ?? 0) +
                                    (version.cook_minutes ?? 0)}{' '}
                                min
                            </span>
                        </div>
                    </header>

                    {editing && (
                        <RecipeVersionForm
                            recipe={recipe}
                            onSaved={() => setEditing(false)}
                        />
                    )}

                    {version.preparation_notices.length > 0 && (
                        <section className="mt-7 border-l-2 border-amber-500 pl-4">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <AlertTriangle className="size-4" /> Before you
                                start
                            </h2>
                            {version.preparation_notices.map((notice) => (
                                <p
                                    key={notice.id}
                                    className="mt-2 text-sm text-muted-foreground"
                                >
                                    {notice.instruction}
                                    {notice.lead_minutes
                                        ? ` · ${notice.lead_minutes} minutes ahead`
                                        : ''}
                                </p>
                            ))}
                        </section>
                    )}

                    <div className="mt-9 grid gap-10 md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                        <section>
                            <h2 className="text-lg font-semibold">
                                Ingredients
                            </h2>
                            <ul className="mt-4 divide-y text-sm">
                                {version.ingredients.map((ingredient) => (
                                    <li
                                        key={ingredient.id}
                                        className="flex justify-between gap-4 py-3"
                                    >
                                        <span>{ingredient.name}</span>
                                        <span className="shrink-0 text-muted-foreground">
                                            {quantity(
                                                ingredient.quantity,
                                                ingredient.unit,
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {version.equipment.length > 0 && (
                                <div className="mt-8">
                                    <h2 className="text-sm font-semibold">
                                        Equipment
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {version.equipment
                                            .map((item) => item.name)
                                            .join(', ')}
                                    </p>
                                </div>
                            )}
                        </section>
                        <section>
                            <h2 className="text-lg font-semibold">Method</h2>
                            <ol className="mt-4 space-y-6">
                                {version.steps.map((step, index) => (
                                    <li
                                        key={step.id}
                                        className="grid grid-cols-[1.75rem_1fr] gap-3 text-sm leading-6"
                                    >
                                        <span className="flex size-7 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                            {index + 1}
                                        </span>
                                        <span>
                                            {step.instruction}
                                            {step.timer_minutes && (
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {step.timer_minutes} minute
                                                    timer
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    </div>

                    {(version.storage_guidance || version.notes) && (
                        <section className="mt-10 grid gap-6 border-t pt-7 sm:grid-cols-2">
                            {version.storage_guidance && (
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        Storage and leftovers
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {version.storage_guidance}
                                    </p>
                                </div>
                            )}
                            {version.notes && (
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        Recipe notes
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {version.notes}
                                    </p>
                                </div>
                            )}
                        </section>
                    )}

                    {(recipe.versions?.length ?? 0) > 1 && (
                        <section className="mt-12 border-t pt-7">
                            <h2 className="text-sm font-semibold">
                                Version history
                            </h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {recipe.versions
                                    ?.map((item) => `v${item.version}`)
                                    .join(' · ')}
                            </p>
                        </section>
                    )}
                </article>
            </main>
        </>
    );
}
