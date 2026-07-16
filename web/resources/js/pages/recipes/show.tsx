import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Clock3, ExternalLink, UsersRound } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import type { Recipe } from '@/features/recipes/types';

function quantity(value: number | null, unit: string | null) {
    if (value === null) {
        return '';
    }

    return `${value}${unit ? ` ${unit}` : ''}`;
}

export default function RecipeShow({ recipe }: { recipe: Recipe }) {
    const version = recipe.versions?.[0];

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
