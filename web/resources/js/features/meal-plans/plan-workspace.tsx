import { Link, router, useForm } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    Check,
    ChevronDown,
    Clock3,
    CircleDollarSign,
    Ellipsis,
    GripVertical,
    Pencil,
    Plus,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import PlannedMealMoveController from '@/actions/App/Http/Controllers/PlannedMealMoveController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { formatDay } from './format-day';
import type { MealPlanWorkspace, MealSlot, PlannedMeal } from './types';

function moveMeal(
    meal: PlannedMeal,
    target: MealSlot,
    expectedRevision: number,
) {
    router.put(
        PlannedMealMoveController.url(meal.id),
        { meal_slot_id: target.id, expected_revision: expectedRevision },
        { preserveScroll: true },
    );
}

function MealExplanation({ meal }: { meal: PlannedMeal }) {
    const explanation = meal.recommendation_explanation;

    if (!explanation) {
        return null;
    }

    return (
        <details className="mt-3 text-xs text-muted-foreground">
            <summary className="cursor-pointer font-medium text-foreground">
                Why this fits
            </summary>
            <div className="mt-2 space-y-1.5 border-l pl-3 leading-5">
                <p>{explanation.safety}</p>
                <p>{explanation.recency}</p>
                <p>{explanation.effort}</p>
                <p>{explanation.cost}</p>
                {explanation.preferences.length > 0 && (
                    <p>
                        Preference matches:{' '}
                        {explanation.preferences
                            .map(
                                (item) => `${item.subject} (${item.sentiment})`,
                            )
                            .join(', ')}
                    </p>
                )}
            </div>
        </details>
    );
}

function slotServingCount(slot: MealSlot) {
    return slot.participants.reduce(
        (total, participant) => total + (participant.pivot?.servings ?? 0),
        0,
    );
}

function ParticipantEditor({
    workspace,
    slot,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
}) {
    const form = useForm({
        participants: workspace.household.people.map((person) => ({
            person_id: person.id,
            servings:
                slot.participants.find((item) => item.id === person.id)?.pivot
                    ?.servings ?? 0,
        })),
        expected_revision: workspace.plan.revision,
    });
    const selectedCount = form.data.participants.filter(
        (participant) => participant.servings > 0,
    ).length;

    const setServings = (index: number, servings: number) => {
        const participants = [...form.data.participants];
        participants[index] = {
            ...participants[index],
            servings,
        };
        form.setData('participants', participants);
    };

    return (
        <details className="mt-3 border-t pt-3">
            <summary className="flex cursor-pointer list-none items-center gap-2 text-xs text-muted-foreground">
                <UsersRound className="size-3.5" /> Participants and servings
                <ChevronDown className="ml-auto size-3.5" />
            </summary>
            <form
                className="mt-3 space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        participants: data.participants.filter(
                            (participant) => participant.servings > 0,
                        ),
                    }));
                    form.put(`/meal-slots/${slot.id}/participants`, {
                        preserveScroll: true,
                    });
                }}
            >
                {workspace.household.people.map((person, index) => {
                    const inputId = `slot-${slot.id}-person-${person.id}`;

                    return (
                        <div
                            key={person.id}
                            data-participant-row
                            className="grid min-h-8 grid-cols-[minmax(0,1fr)_5rem] items-center gap-3 text-xs"
                        >
                            <Label htmlFor={inputId} className="font-normal">
                                {person.name}
                            </Label>
                            <Input
                                id={inputId}
                                data-participant-control
                                className="h-8 w-full"
                                aria-label={`${person.name} servings`}
                                type="number"
                                inputMode="decimal"
                                min="0"
                                max="999"
                                step="0.25"
                                value={form.data.participants[index].servings}
                                onChange={(event) =>
                                    setServings(
                                        index,
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </div>
                    );
                })}
                {form.errors.participants && (
                    <p className="text-xs text-destructive">
                        {form.errors.participants}
                    </p>
                )}
                <div className="flex justify-end pt-1">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={form.processing || selectedCount === 0}
                    >
                        Save participants
                    </Button>
                </div>
            </form>
        </details>
    );
}

function SelectedMealEditor({
    workspace,
    slot,
    meal,
    moveTargets,
    showDragHandle = true,
    showMealControls = true,
    onMove,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
    meal: PlannedMeal;
    moveTargets: MealSlot[];
    showDragHandle?: boolean;
    showMealControls?: boolean;
    onMove?: () => void;
}) {
    const form = useForm({
        servings: meal.servings,
        status: meal.status,
        notes: meal.notes ?? '',
        expected_revision: workspace.plan.revision,
    });

    return (
        <div>
            <div className="flex items-start gap-2">
                {showDragHandle && (
                    <GripVertical className="mt-0.5 hidden size-4 shrink-0 text-muted-foreground sm:block" />
                )}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="leading-5 font-medium">{meal.title}</p>
                        {meal.status === 'skipped' && (
                            <Badge variant="secondary">Skipped</Badge>
                        )}
                        {meal.recipe_version && (
                            <Badge variant="outline">
                                v{meal.recipe_version.version}
                            </Badge>
                        )}
                    </div>
                    {meal.summary && (
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {meal.summary}
                        </p>
                    )}
                    {(meal.estimated_minutes || meal.estimated_cost) && (
                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                            {meal.estimated_minutes && (
                                <span className="flex items-center gap-1">
                                    <Clock3 className="size-3" />
                                    {meal.estimated_minutes} min
                                </span>
                            )}
                            {meal.estimated_cost && (
                                <span
                                    className="flex items-center gap-1"
                                    data-numeric="tabular"
                                >
                                    <CircleDollarSign className="size-3" />
                                    ~${meal.estimated_cost.toFixed(2)}
                                </span>
                            )}
                        </div>
                    )}
                    {meal.recipe_version && (
                        <Link
                            href={`/recipes/${meal.recipe_version.recipe_id}`}
                            className="mt-2 inline-block text-xs text-primary hover:underline"
                        >
                            Open recipe snapshot
                        </Link>
                    )}
                    <MealExplanation meal={meal} />
                </div>
            </div>
            {showMealControls && (
                <details className="mt-3 border-t pt-3">
                    <summary className="cursor-pointer list-none text-xs text-muted-foreground">
                        Edit meal
                    </summary>
                    <form
                        className="mt-3 grid gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(`/planned-meals/${meal.id}`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Input
                                aria-label="Meal servings"
                                type="number"
                                min="0.25"
                                step="0.25"
                                value={form.data.servings}
                                onChange={(event) =>
                                    form.setData(
                                        'servings',
                                        Number(event.target.value),
                                    )
                                }
                            />
                            <select
                                aria-label="Meal status"
                                className="rounded-md border bg-background px-2 text-xs"
                                value={form.data.status}
                                onChange={(event) =>
                                    form.setData(
                                        'status',
                                        event.target.value as
                                            'planned' | 'skipped',
                                    )
                                }
                            >
                                <option value="planned">Planned</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>
                        <Input
                            aria-label="Meal notes"
                            placeholder="Notes"
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={form.processing}
                        >
                            Save meal
                        </Button>
                    </form>
                </details>
            )}
            {moveTargets.length > 0 && (
                <label className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                    Move or swap
                    <select
                        aria-label={`Move ${meal.title}`}
                        defaultValue=""
                        className="min-w-0 flex-1 rounded border bg-background px-2 py-1"
                        onChange={(event) => {
                            const target = moveTargets.find(
                                (item) =>
                                    item.id === Number(event.target.value),
                            );

                            if (target) {
                                onMove?.();
                                moveMeal(meal, target, workspace.plan.revision);
                            }
                        }}
                    >
                        <option value="" disabled>
                            Choose another slot
                        </option>
                        {moveTargets.map((target) => (
                            <option key={target.id} value={target.id}>
                                {formatDay(target.date)} · {target.kind}
                                {target.planned_meal
                                    ? ` · swap with ${target.planned_meal.title}`
                                    : ' · open'}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <ParticipantEditor workspace={workspace} slot={slot} />
        </div>
    );
}

function OpenMealEditor({
    workspace,
    slot,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
}) {
    const [type, setType] = useState('recipe');
    const sourceMeals = workspace.plan.slots.reduce<PlannedMeal[]>(
        (meals, item) => {
            if (item.planned_meal) {
                meals.push(item.planned_meal);
            }

            return meals;
        },
        [],
    );
    const form = useForm({
        type: 'recipe',
        recipe_version_id: (workspace.recipes[0]?.latest_version?.id ?? '') as
            number | '',
        source_planned_meal_id: '' as number | '',
        title: '',
        servings: Math.max(1, slotServingCount(slot)),
        expected_revision: workspace.plan.revision,
    });

    return (
        <div>
            <p className="text-sm text-muted-foreground">Open</p>
            <form
                className="mt-3 space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/meal-slots/${slot.id}/planned-meal`, {
                        preserveScroll: true,
                    });
                }}
            >
                <select
                    aria-label="Meal choice type"
                    className="h-9 w-full rounded-md border bg-background px-2 text-xs"
                    value={type}
                    onChange={(event) => {
                        setType(event.target.value);
                        form.setData('type', event.target.value);
                    }}
                >
                    <option value="recipe">Recipe</option>
                    <option value="custom">Custom meal</option>
                    <option value="leftovers">Leftovers</option>
                    <option value="takeaway">Takeaway</option>
                    <option value="eating_out">Eating out</option>
                    <option value="open">Keep open</option>
                </select>
                {type === 'recipe' ? (
                    <select
                        aria-label="Recipe"
                        className="h-9 w-full rounded-md border bg-background px-2 text-xs"
                        value={form.data.recipe_version_id}
                        onChange={(event) =>
                            form.setData(
                                'recipe_version_id',
                                Number(event.target.value),
                            )
                        }
                    >
                        {workspace.recipes.length === 0 && (
                            <option value="">Create a recipe first</option>
                        )}
                        {workspace.recipes.map((recipe) => (
                            <option
                                key={recipe.id}
                                value={recipe.latest_version.id}
                            >
                                {recipe.title} · v
                                {recipe.latest_version.version}
                            </option>
                        ))}
                    </select>
                ) : type === 'leftovers' ? (
                    <select
                        aria-label="Leftovers source meal"
                        className="h-9 w-full rounded-md border bg-background px-2 text-xs"
                        value={form.data.source_planned_meal_id}
                        onChange={(event) =>
                            form.setData(
                                'source_planned_meal_id',
                                Number(event.target.value),
                            )
                        }
                    >
                        <option value="">Choose the original meal</option>
                        {sourceMeals.map((meal) => (
                            <option key={meal.id} value={meal.id}>
                                {meal.title}
                            </option>
                        ))}
                    </select>
                ) : (
                    <Input
                        aria-label="Meal name"
                        placeholder={
                            type === 'leftovers'
                                ? 'What are the leftovers from?'
                                : 'Meal name'
                        }
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                    />
                )}
                <Button
                    data-testid={`select-meal-${slot.id}`}
                    size="sm"
                    disabled={
                        form.processing ||
                        (type === 'recipe' && workspace.recipes.length === 0)
                    }
                >
                    <Check /> Select meal
                </Button>
            </form>
            <ParticipantEditor workspace={workspace} slot={slot} />
        </div>
    );
}

function SlotCard({
    workspace,
    slot,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
}) {
    const [over, setOver] = useState(false);

    return (
        <article
            data-testid={`meal-slot-${slot.id}`}
            data-open={slot.planned_meal ? 'false' : 'true'}
            draggable={Boolean(slot.planned_meal)}
            onDragStart={(event) => {
                if (slot.planned_meal) {
                    event.dataTransfer.setData(
                        'text/chef-planned-meal',
                        String(slot.planned_meal.id),
                    );
                }
            }}
            onDragOver={(event) => {
                event.preventDefault();
                setOver(true);
            }}
            onDragLeave={() => setOver(false)}
            onDrop={(event) => {
                event.preventDefault();
                setOver(false);
                const mealId = Number(
                    event.dataTransfer.getData('text/chef-planned-meal'),
                );
                const meal = workspace.plan.slots
                    .map((item) => item.planned_meal)
                    .find((item) => item?.id === mealId);

                if (meal && meal.meal_slot_id !== slot.id) {
                    moveMeal(meal, slot, workspace.plan.revision);
                }
            }}
            className={cn(
                'rounded-lg border bg-background p-3 transition-colors',
                over && 'border-primary bg-primary/5',
            )}
        >
            <div className="mb-2 flex items-center justify-between gap-2">
                <p className="text-xs font-medium capitalize">
                    {slot.label ?? slot.kind}
                </p>
                <span className="text-[11px] text-muted-foreground">
                    {slotServingCount(slot)} eating
                </span>
            </div>
            {slot.planned_meal ? (
                <SelectedMealEditor
                    workspace={workspace}
                    slot={slot}
                    meal={slot.planned_meal}
                    moveTargets={workspace.plan.slots.filter(
                        (target) => target.id !== slot.id,
                    )}
                    showMealControls={false}
                />
            ) : (
                <OpenMealEditor workspace={workspace} slot={slot} />
            )}
        </article>
    );
}

function CalendarSlotCard({
    workspace,
    slot,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
}) {
    const [over, setOver] = useState(false);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const meal = slot.planned_meal;
    const label = slot.label ?? slot.kind;
    const displayLabel = label.charAt(0).toUpperCase() + label.slice(1);

    return (
        <>
            <article
                data-testid={`meal-slot-${slot.id}`}
                data-open={meal ? 'false' : 'true'}
                draggable={Boolean(meal)}
                onDragStart={(event) => {
                    if (meal) {
                        event.dataTransfer.setData(
                            'text/chef-planned-meal',
                            String(meal.id),
                        );
                    }
                }}
                onDragOver={(event) => {
                    event.preventDefault();
                    setOver(true);
                }}
                onDragLeave={() => setOver(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setOver(false);
                    const mealId = Number(
                        event.dataTransfer.getData('text/chef-planned-meal'),
                    );
                    const droppedMeal = workspace.plan.slots
                        .map((item) => item.planned_meal)
                        .find((item) => item?.id === mealId);

                    if (droppedMeal && droppedMeal.meal_slot_id !== slot.id) {
                        moveMeal(droppedMeal, slot, workspace.plan.revision);
                    }
                }}
                className={cn(
                    'rounded-xl bg-background px-3 py-2.5 shadow-xs ring-1 ring-border/40 transition-[background-color,box-shadow]',
                    over && 'bg-primary/5 ring-primary',
                )}
            >
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <p className="text-[11px] font-medium tracking-wide text-muted-foreground">
                            {displayLabel}
                        </p>
                        {meal ? (
                            <>
                                <p className="mt-1 line-clamp-2 text-sm leading-5 font-medium">
                                    {meal.title}
                                </p>
                                <p
                                    className="mt-1.5 truncate text-[11px] text-muted-foreground"
                                    data-numeric="tabular"
                                >
                                    {slotServingCount(slot)}{' '}
                                    {slotServingCount(slot) === 1
                                        ? 'person'
                                        : 'people'}
                                    {meal.estimated_minutes
                                        ? ` · ${meal.estimated_minutes} min`
                                        : ''}
                                    {meal.estimated_cost
                                        ? ` · ~$${meal.estimated_cost.toFixed(2)}`
                                        : ''}
                                </p>
                            </>
                        ) : (
                            <button
                                type="button"
                                className="mt-1 text-sm font-medium text-primary hover:underline"
                                onClick={() => setDetailsOpen(true)}
                            >
                                Choose a meal
                            </button>
                        )}
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="-mt-1 -mr-1 size-7 shrink-0"
                                aria-label={`Open actions for ${displayLabel} on ${formatDay(slot.date)}`}
                            >
                                <Ellipsis />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => setDetailsOpen(true)}
                            >
                                {meal ? <Pencil /> : <Plus />}
                                {meal
                                    ? 'Meal details and actions'
                                    : 'Choose a meal'}
                            </DropdownMenuItem>
                            {meal?.recipe_version && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={`/recipes/${meal.recipe_version.recipe_id}`}
                                        >
                                            <BookOpen /> Open recipe
                                        </Link>
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </article>
            <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
                <DialogContent className="max-h-[85svh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {meal ? 'Meal details' : `Choose ${displayLabel}`}
                        </DialogTitle>
                        <DialogDescription>
                            {formatDay(slot.date)} · {displayLabel} ·{' '}
                            {slotServingCount(slot)}{' '}
                            {slotServingCount(slot) === 1 ? 'person' : 'people'}
                        </DialogDescription>
                    </DialogHeader>
                    {meal ? (
                        <SelectedMealEditor
                            workspace={workspace}
                            slot={slot}
                            meal={meal}
                            moveTargets={workspace.plan.slots.filter(
                                (target) => target.id !== slot.id,
                            )}
                            showDragHandle={false}
                            onMove={() => setDetailsOpen(false)}
                        />
                    ) : (
                        <OpenMealEditor workspace={workspace} slot={slot} />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function planDates(startsOn: string, endsOn: string) {
    const dates: string[] = [];
    const cursor = new Date(`${startsOn.slice(0, 10)}T00:00:00Z`);
    const end = new Date(`${endsOn.slice(0, 10)}T00:00:00Z`);

    while (cursor <= end) {
        dates.push(cursor.toISOString().slice(0, 10));
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return dates;
}

export function PlanWorkspace({
    workspace,
    view,
}: {
    workspace: MealPlanWorkspace;
    view: 'calendar' | 'list';
}) {
    const emptySlots = workspace.plan.slots.filter(
        (slot) => slot.planned_meal === null,
    );
    const dates = planDates(workspace.plan.starts_on, workspace.plan.ends_on);
    const plannedSlotCount = workspace.plan.slots.filter(
        (slot) => slot.planned_meal !== null,
    ).length;

    if (view === 'list') {
        return (
            <main className="min-h-0 min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl space-y-5 px-5 py-7 sm:px-8">
                    {workspace.plan.slots.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                            Add meal slots from the plan inspector or ask Chef
                            in the conversation.
                        </p>
                    )}
                    {dates.map((date) => {
                        const slots = workspace.plan.slots.filter(
                            (slot) => slot.date.slice(0, 10) === date,
                        );

                        if (slots.length === 0) {
                            return null;
                        }

                        return (
                            <section key={date}>
                                <h2 className="mb-2 text-sm font-semibold">
                                    {formatDay(date)}
                                </h2>
                                <div className="space-y-2">
                                    {slots.map((slot) => (
                                        <SlotCard
                                            key={slot.id}
                                            workspace={workspace}
                                            slot={slot}
                                        />
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </main>
        );
    }

    return (
        <main className="min-h-0 min-w-0 flex-1 overflow-y-auto bg-muted/10">
            <div className="p-4 sm:p-5">
                <header className="mb-5">
                    <div>
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <CalendarDays className="size-4" /> Meal calendar
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {plannedSlotCount} planned · {emptySlots.length}{' '}
                            open
                        </p>
                    </div>
                </header>
                <div
                    data-testid="calendar-grid"
                    className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,15rem),1fr))] items-start gap-3"
                >
                    {dates.map((date) => {
                        const slots = workspace.plan.slots.filter(
                            (slot) => slot.date.slice(0, 10) === date,
                        );

                        return (
                            <section
                                key={date}
                                data-testid="calendar-day"
                                className="min-w-0"
                            >
                                <div className="mb-2 px-1">
                                    <h3 className="text-sm font-semibold">
                                        {formatDay(date)}
                                    </h3>
                                </div>
                                <div className="space-y-2">
                                    {slots.map((slot) => (
                                        <CalendarSlotCard
                                            key={slot.id}
                                            workspace={workspace}
                                            slot={slot}
                                        />
                                    ))}
                                    {slots.length === 0 && (
                                        <p className="rounded-xl bg-background/60 px-3 py-6 text-center text-xs text-muted-foreground">
                                            No meal slots
                                        </p>
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </div>
        </main>
    );
}
