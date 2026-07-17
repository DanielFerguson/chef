import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Maximize2,
    PackageCheck,
    UsersRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CookingTimer } from '@/features/cooking/cooking-timer';
import type {
    CookingMeal,
    CookingPerson,
    MealFeedback,
    MealOutcomeStatus,
    ShoppingChoice,
} from '@/features/cooking/types';

const OUTCOME_STATUSES = [
    'cooked',
    'skipped',
    'postponed',
    'replaced',
    'leftovers',
    'ate_out',
] as const;

function isMealOutcomeStatus(value: string): value is MealOutcomeStatus {
    return (OUTCOME_STATUSES as readonly string[]).includes(value);
}

function useScreenWake(active: boolean) {
    const [awake, setAwake] = useState(false);

    useEffect(() => {
        if (!active) {
            return;
        }

        let released = false;
        let lock: { release: () => Promise<void> } | null = null;
        const wakeLock = (
            navigator as Navigator & {
                wakeLock?: {
                    request: (
                        type: 'screen',
                    ) => Promise<{ release: () => Promise<void> }>;
                };
            }
        ).wakeLock;

        if (wakeLock) {
            void wakeLock
                .request('screen')
                .then((sentinel) => {
                    if (released) {
                        return sentinel.release();
                    }

                    lock = sentinel;
                    setAwake(true);
                })
                .catch(() => setAwake(false));
        }

        return () => {
            released = true;
            setAwake(false);
            void lock?.release();
        };
    }, [active]);

    return awake;
}

function OutcomeDialog({ meal }: { meal: CookingMeal }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        status: MealOutcomeStatus;
        replacement_title: string;
        postponed_until: string;
        leftover_servings: string;
        notes: string;
    }>({
        status: meal.outcome?.status ?? 'cooked',
        replacement_title: meal.outcome?.replacement_title ?? '',
        postponed_until: meal.outcome?.postponed_until?.slice(0, 10) ?? '',
        leftover_servings: meal.outcome?.leftover_servings?.toString() ?? '',
        notes: meal.outcome?.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            replacement_title: data.replacement_title || null,
            postponed_until: data.postponed_until || null,
            leftover_servings: data.leftover_servings
                ? Number(data.leftover_servings)
                : null,
            notes: data.notes || null,
        }));
        form.put(`/planned-meals/${meal.id}/outcome`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="lg" className="min-h-12 px-6">
                    <Check />
                    {meal.outcome?.completed_at
                        ? 'Update outcome'
                        : 'Finish and record outcome'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>What happened with this meal?</DialogTitle>
                        <DialogDescription>
                            Record the outcome first. Feedback is optional and
                            belongs to each person.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="mt-5 space-y-4">
                        <Select
                            value={form.data.status ?? 'cooked'}
                            onValueChange={(value) => {
                                if (isMealOutcomeStatus(value)) {
                                    form.setData('status', value);
                                }
                            }}
                        >
                            <SelectTrigger
                                className="w-full"
                                aria-label="Meal outcome"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cooked">Cooked</SelectItem>
                                <SelectItem value="leftovers">
                                    Cooked with leftovers
                                </SelectItem>
                                <SelectItem value="skipped">Skipped</SelectItem>
                                <SelectItem value="postponed">
                                    Postponed
                                </SelectItem>
                                <SelectItem value="replaced">
                                    Replaced
                                </SelectItem>
                                <SelectItem value="ate_out">Ate out</SelectItem>
                            </SelectContent>
                        </Select>
                        {form.data.status === 'replaced' && (
                            <Input
                                aria-label="Replacement meal"
                                placeholder="What replaced it?"
                                value={form.data.replacement_title}
                                onChange={(event) =>
                                    form.setData(
                                        'replacement_title',
                                        event.target.value,
                                    )
                                }
                            />
                        )}
                        {form.data.status === 'postponed' && (
                            <Input
                                aria-label="Postponed until"
                                type="date"
                                value={form.data.postponed_until}
                                onChange={(event) =>
                                    form.setData(
                                        'postponed_until',
                                        event.target.value,
                                    )
                                }
                            />
                        )}
                        {form.data.status === 'leftovers' && (
                            <Input
                                aria-label="Leftover servings"
                                type="number"
                                min="0.25"
                                step="0.25"
                                placeholder="Servings left"
                                value={form.data.leftover_servings}
                                onChange={(event) =>
                                    form.setData(
                                        'leftover_servings',
                                        event.target.value,
                                    )
                                }
                            />
                        )}
                        <textarea
                            aria-label="Outcome notes"
                            className="min-h-24 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder="Anything worth remembering? (optional)"
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                        {Object.values(form.errors).map((error) => (
                            <p
                                key={error}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        ))}
                    </div>
                    <DialogFooter className="mt-6">
                        <Button type="submit" disabled={form.processing}>
                            Save outcome
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FeedbackForm({
    meal,
    person,
    existing,
}: {
    meal: CookingMeal;
    person: CookingPerson;
    existing?: MealFeedback;
}) {
    const outcome = meal.outcome;
    const form = useForm({
        rating: existing?.rating ?? 'neutral',
        portion: existing?.portion ?? '',
        effort: existing?.effort ?? '',
        cost: existing?.cost ?? '',
        leftovers: existing?.leftovers ?? '',
        notes: existing?.notes ?? '',
        recipe_adjustment: existing?.recipe_adjustment ?? '',
    });

    if (!outcome) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            portion: data.portion || null,
            effort: data.effort || null,
            cost: data.cost || null,
            leftovers: data.leftovers || null,
            notes: data.notes || null,
            recipe_adjustment: data.recipe_adjustment || null,
        }));
        form.put(`/meal-outcomes/${outcome.id}/people/${person.id}/feedback`, {
            preserveScroll: true,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="border-t py-6 first:border-t-0 first:pt-0"
            data-feedback-person={person.id}
        >
            <div className="flex items-center justify-between gap-3">
                <h3 className="font-medium">{person.name}</h3>
                {existing && <Badge variant="secondary">Feedback saved</Badge>}
            </div>
            <div className="mt-3 grid grid-cols-4 gap-2">
                {(['dislike', 'neutral', 'like', 'favourite'] as const).map(
                    (rating) => (
                        <Button
                            key={rating}
                            type="button"
                            variant={
                                form.data.rating === rating
                                    ? 'default'
                                    : 'outline'
                            }
                            className="h-auto min-h-11 px-2 capitalize"
                            aria-label={`${rating} for ${person.name}`}
                            onClick={() => form.setData('rating', rating)}
                        >
                            {rating}
                        </Button>
                    ),
                )}
            </div>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <Select
                    value={form.data.portion}
                    onValueChange={(value) => form.setData('portion', value)}
                >
                    <SelectTrigger
                        className="w-full"
                        aria-label={`Portion for ${person.name}`}
                    >
                        <SelectValue placeholder="Portion" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="too_small">Too small</SelectItem>
                        <SelectItem value="right">Portion was right</SelectItem>
                        <SelectItem value="too_large">Too large</SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    value={form.data.effort}
                    onValueChange={(value) => form.setData('effort', value)}
                >
                    <SelectTrigger
                        className="w-full"
                        aria-label={`Effort for ${person.name}`}
                    >
                        <SelectValue placeholder="Effort" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="easy">Easy</SelectItem>
                        <SelectItem value="right">Effort was right</SelectItem>
                        <SelectItem value="too_much">
                            Too much effort
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <Select
                    value={form.data.cost}
                    onValueChange={(value) => form.setData('cost', value)}
                >
                    <SelectTrigger
                        className="w-full"
                        aria-label={`Cost for ${person.name}`}
                    >
                        <SelectValue placeholder="Cost" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="good_value">Good value</SelectItem>
                        <SelectItem value="right">Cost was right</SelectItem>
                        <SelectItem value="too_high">Too expensive</SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    value={form.data.leftovers}
                    onValueChange={(value) => form.setData('leftovers', value)}
                >
                    <SelectTrigger
                        className="w-full"
                        aria-label={`Leftovers for ${person.name}`}
                    >
                        <SelectValue placeholder="Leftovers" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">None left</SelectItem>
                        <SelectItem value="some">Some left</SelectItem>
                        <SelectItem value="plenty">Plenty left</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <textarea
                aria-label={`Feedback notes for ${person.name}`}
                className="mt-3 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                placeholder="What worked or did not? (optional)"
                value={form.data.notes}
                onChange={(event) => form.setData('notes', event.target.value)}
            />
            <Input
                className="mt-3"
                aria-label={`Recipe adjustment for ${person.name}`}
                placeholder="Recipe change to keep next time (optional)"
                value={form.data.recipe_adjustment}
                onChange={(event) =>
                    form.setData('recipe_adjustment', event.target.value)
                }
            />
            <Button className="mt-3" type="submit" disabled={form.processing}>
                Save {person.name}&apos;s feedback
            </Button>
        </form>
    );
}

export default function CookingShow({
    meal,
    shoppingChoices,
}: {
    meal: CookingMeal;
    shoppingChoices: ShoppingChoice[];
}) {
    const recipe = meal.recipe_version;
    const outcome = meal.outcome;
    const [currentStep, setCurrentStep] = useState(
        outcome?.current_step_position ?? 1,
    );
    const [fullscreen, setFullscreen] = useState(false);
    const awake = useScreenWake(
        outcome?.started_at != null && outcome.completed_at === null,
    );
    const step = recipe?.steps[currentStep - 1];
    const canCollectFeedback =
        outcome?.status === 'cooked' || outcome?.status === 'leftovers';

    const changeStep = (position: number) => {
        if (!outcome) {
            return;
        }

        setCurrentStep(position);
        router.put(
            `/meal-outcomes/${outcome.id}/progress`,
            { step: position },
            { preserveScroll: true, preserveState: true },
        );
    };

    const toggleFullscreen = () => {
        if (document.fullscreenElement) {
            void document.exitFullscreen();
            setFullscreen(false);
        } else {
            void document.documentElement.requestFullscreen();
            setFullscreen(true);
        }
    };

    return (
        <>
            <Head title={`Cook ${meal.title}`} />
            <div className="flex min-h-0 flex-1 flex-col bg-background lg:flex-row">
                <main className="min-w-0 flex-1 overflow-y-auto">
                    <div className="mx-auto w-full max-w-4xl px-5 py-6 sm:px-8 lg:py-10">
                        <header className="flex items-start justify-between gap-4 border-b pb-6">
                            <div>
                                <Link
                                    href="/dashboard"
                                    className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                                >
                                    <ArrowLeft className="size-4" /> Today
                                </Link>
                                <p className="mt-5 text-xs font-medium tracking-wide text-primary uppercase">
                                    {meal.meal_slot.kind} ·{' '}
                                    {meal.meal_slot.date.slice(0, 10)}
                                </p>
                                <h1 className="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">
                                    {meal.title}
                                </h1>
                                {recipe?.summary && (
                                    <p className="mt-3 max-w-2xl leading-7 text-muted-foreground">
                                        {recipe.summary}
                                    </p>
                                )}
                            </div>
                            <Button
                                variant="outline"
                                size="icon"
                                onClick={toggleFullscreen}
                                aria-pressed={fullscreen}
                                aria-label="Toggle distraction-free fullscreen"
                            >
                                <Maximize2 />
                                <span className="sr-only">
                                    Toggle distraction-free fullscreen
                                </span>
                            </Button>
                        </header>

                        {!outcome?.started_at && recipe && (
                            <section className="py-12 text-center">
                                <h2 className="text-xl font-semibold">
                                    Ready when you are
                                </h2>
                                <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-muted-foreground">
                                    Cooking mode keeps the current step large,
                                    ingredients close, and the screen awake when
                                    your browser supports it.
                                </p>
                                <Button
                                    asChild
                                    size="lg"
                                    className="mt-6 min-h-12 px-7"
                                >
                                    <Link
                                        href={`/planned-meals/${meal.id}/cook`}
                                        method="post"
                                        as="button"
                                    >
                                        Start cooking <ArrowRight />
                                    </Link>
                                </Button>
                            </section>
                        )}

                        {outcome?.started_at && recipe && step && (
                            <section
                                className="py-8"
                                aria-labelledby="current-step-title"
                            >
                                <div className="flex items-center justify-between gap-4">
                                    <p className="text-sm font-medium text-muted-foreground">
                                        Step {currentStep} of{' '}
                                        {recipe.steps.length}
                                    </p>
                                    <Badge variant="outline">
                                        {awake
                                            ? 'Screen awake'
                                            : 'Cooking mode'}
                                    </Badge>
                                </div>
                                <h2 id="current-step-title" className="sr-only">
                                    Current step
                                </h2>
                                <p className="mt-6 text-2xl leading-relaxed font-medium text-balance sm:text-3xl sm:leading-relaxed">
                                    {step.instruction}
                                </p>
                                {step.timer_minutes && (
                                    <CookingTimer
                                        mealId={meal.id}
                                        stepId={step.id}
                                        minutes={step.timer_minutes}
                                    />
                                )}
                                <div className="mt-8 flex items-center justify-between gap-3 border-t pt-6">
                                    <Button
                                        size="lg"
                                        variant="outline"
                                        disabled={currentStep === 1}
                                        onClick={() =>
                                            changeStep(currentStep - 1)
                                        }
                                        className="min-h-12"
                                    >
                                        <ChevronLeft /> Previous
                                    </Button>
                                    {currentStep < recipe.steps.length ? (
                                        <Button
                                            size="lg"
                                            onClick={() =>
                                                changeStep(currentStep + 1)
                                            }
                                            className="min-h-12"
                                        >
                                            Next <ChevronRight />
                                        </Button>
                                    ) : (
                                        <OutcomeDialog meal={meal} />
                                    )}
                                </div>
                            </section>
                        )}

                        {!recipe && (
                            <section className="py-12 text-center">
                                <h2 className="text-xl font-semibold">
                                    Record what happened
                                </h2>
                                <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-muted-foreground">
                                    This meal does not need recipe steps. Record
                                    whether it was eaten, postponed, replaced,
                                    skipped, or became leftovers.
                                </p>
                                <div className="mt-6">
                                    <OutcomeDialog meal={meal} />
                                </div>
                            </section>
                        )}

                        {outcome?.completed_at && (
                            <section
                                className="border-t py-8"
                                id="meal-feedback"
                            >
                                <div className="mb-7">
                                    <Badge variant="secondary">
                                        Outcome recorded ·{' '}
                                        {outcome.status?.replace('_', ' ')}
                                    </Badge>
                                    <h2 className="mt-3 text-2xl font-semibold tracking-tight">
                                        {canCollectFeedback
                                            ? 'How was it for everyone?'
                                            : 'Meal outcome saved'}
                                    </h2>
                                    {canCollectFeedback ? (
                                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                            Feedback is optional and
                                            person-specific. Repeated patterns
                                            become reviewable preference
                                            candidates, never safety rules.
                                        </p>
                                    ) : (
                                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                            There is no recipe feedback to
                                            collect because this meal was not
                                            cooked.
                                        </p>
                                    )}
                                </div>
                                {canCollectFeedback &&
                                    meal.meal_slot.participants.map(
                                        (person) => (
                                            <FeedbackForm
                                                key={person.id}
                                                meal={meal}
                                                person={person}
                                                existing={outcome.feedback.find(
                                                    (item) =>
                                                        item.person_id ===
                                                        person.id,
                                                )}
                                            />
                                        ),
                                    )}
                                <Button asChild variant="outline">
                                    <Link href="/dashboard">Back to Today</Link>
                                </Button>
                            </section>
                        )}
                    </div>
                </main>

                <aside className="border-t bg-muted/20 p-5 lg:w-80 lg:overflow-y-auto lg:border-t-0 lg:border-l xl:w-96">
                    {recipe ? (
                        <div className="space-y-8">
                            {recipe.preparation_notices.length > 0 && (
                                <section>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <AlertTriangle className="size-4 text-primary" />
                                        Before and during cooking
                                    </h2>
                                    <ul className="mt-3 space-y-3">
                                        {recipe.preparation_notices.map(
                                            (notice) => (
                                                <li
                                                    key={notice.id}
                                                    className="text-sm leading-6 text-muted-foreground"
                                                >
                                                    {notice.instruction}
                                                    {notice.lead_minutes
                                                        ? ` · ${notice.lead_minutes} min ahead`
                                                        : ''}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </section>
                            )}
                            <section>
                                <h2 className="text-sm font-semibold">
                                    Ingredients
                                </h2>
                                <ul className="mt-3 divide-y">
                                    {recipe.ingredients.map((ingredient) => (
                                        <li
                                            key={ingredient.id}
                                            className="flex justify-between gap-4 py-3 text-sm"
                                        >
                                            <span>{ingredient.name}</span>
                                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                                {ingredient.quantity ?? ''}{' '}
                                                {ingredient.unit ?? ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                            {shoppingChoices.length > 0 && (
                                <section>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <PackageCheck className="size-4" />{' '}
                                        Products and substitutions
                                    </h2>
                                    <ul className="mt-3 space-y-3">
                                        {shoppingChoices.map((choice) => (
                                            <li
                                                key={choice.ingredient}
                                                className="text-sm"
                                            >
                                                <p>{choice.product}</p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    For {choice.ingredient}
                                                    {choice.substituted_from
                                                        ? ` · substituted for ${choice.substituted_from}`
                                                        : ''}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                            {recipe.equipment.length > 0 && (
                                <section>
                                    <h2 className="text-sm font-semibold">
                                        Equipment
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {recipe.equipment
                                            .map((item) => item.name)
                                            .join(', ')}
                                    </p>
                                </section>
                            )}
                            {recipe.notes && (
                                <section>
                                    <h2 className="text-sm font-semibold">
                                        Storage and leftovers
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {recipe.notes}
                                    </p>
                                </section>
                            )}
                            <section className="flex flex-wrap gap-4 border-t pt-5 text-sm text-muted-foreground">
                                <span className="flex items-center gap-2">
                                    <UsersRound className="size-4" />{' '}
                                    {meal.servings} servings
                                </span>
                                <span className="flex items-center gap-2">
                                    <Clock3 className="size-4" />
                                    {(recipe.prep_minutes ?? 0) +
                                        (recipe.cook_minutes ?? 0)}{' '}
                                    min
                                </span>
                            </section>
                        </div>
                    ) : (
                        <p className="text-sm leading-6 text-muted-foreground">
                            No cooking details are needed for this meal.
                        </p>
                    )}
                </aside>
            </div>
        </>
    );
}

CookingShow.layout = {
    breadcrumbs: [
        { title: 'Today', href: '/dashboard' },
        { title: 'Cooking', href: '#' },
    ],
};
