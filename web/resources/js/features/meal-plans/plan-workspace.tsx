import { Link, router, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    CircleAlert,
    GripVertical,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import PlannedMealMoveController from '@/actions/App/Http/Controllers/PlannedMealMoveController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { formatDay } from './format-day';
import type { MealPlanWorkspace, MealSlot, PlannedMeal } from './types';

function moveMeal(meal: PlannedMeal, target: MealSlot) {
    router.put(
        PlannedMealMoveController.url(meal.id),
        { meal_slot_id: target.id },
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
                {workspace.household.people.map((person, index) => (
                    <label
                        key={person.id}
                        className="flex items-center justify-between gap-3 text-xs"
                    >
                        <span>{person.name}</span>
                        <Input
                            className="h-8 w-20"
                            aria-label={`${person.name} servings`}
                            type="number"
                            min="0"
                            max="999"
                            step="0.25"
                            value={form.data.participants[index].servings}
                            onChange={(event) => {
                                const participants = [
                                    ...form.data.participants,
                                ];
                                participants[index] = {
                                    ...participants[index],
                                    servings: Number(event.target.value),
                                };
                                form.setData('participants', participants);
                            }}
                        />
                    </label>
                ))}
                <Button size="sm" variant="outline" disabled={form.processing}>
                    Save servings
                </Button>
            </form>
        </details>
    );
}

function SelectedMealEditor({
    workspace,
    slot,
    meal,
    emptySlots,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
    meal: PlannedMeal;
    emptySlots: MealSlot[];
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
                <GripVertical className="mt-0.5 hidden size-4 shrink-0 text-muted-foreground sm:block" />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-medium">{meal.title}</p>
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
                                    event.target.value as 'planned' | 'skipped',
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
            {emptySlots.length > 0 && (
                <label className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                    Move to
                    <select
                        aria-label={`Move ${meal.title}`}
                        defaultValue=""
                        className="min-w-0 flex-1 rounded border bg-background px-2 py-1"
                        onChange={(event) => {
                            const target = emptySlots.find(
                                (item) =>
                                    item.id === Number(event.target.value),
                            );

                            if (target) {
                                moveMeal(meal, target);
                            }
                        }}
                    >
                        <option value="" disabled>
                            Choose an open slot
                        </option>
                        {emptySlots.map((target) => (
                            <option key={target.id} value={target.id}>
                                {formatDay(target.date)} · {target.kind}
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
        servings: Math.max(1, slot.participants.length),
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
    emptySlots,
    compact = false,
}: {
    workspace: MealPlanWorkspace;
    slot: MealSlot;
    emptySlots: MealSlot[];
    compact?: boolean;
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
                if (!slot.planned_meal) {
                    event.preventDefault();
                    setOver(true);
                }
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

                if (meal && !slot.planned_meal) {
                    moveMeal(meal, slot);
                }
            }}
            className={cn(
                'rounded-lg border bg-background p-3 transition-colors',
                over && 'border-primary bg-primary/5',
                compact && 'p-2.5',
            )}
        >
            <div className="mb-2 flex items-center justify-between gap-2">
                <p className="text-xs font-medium capitalize">
                    {slot.label ?? slot.kind}
                </p>
                <span className="text-[11px] text-muted-foreground">
                    {slot.participants.length} eating
                </span>
            </div>
            {slot.planned_meal ? (
                <SelectedMealEditor
                    workspace={workspace}
                    slot={slot}
                    meal={slot.planned_meal}
                    emptySlots={emptySlots}
                />
            ) : (
                <OpenMealEditor workspace={workspace} slot={slot} />
            )}
        </article>
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

    if (view === 'list') {
        return (
            <main className="min-w-0 flex-1 overflow-y-auto">
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
                                            emptySlots={emptySlots}
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
        <main className="min-w-0 flex-1 overflow-auto">
            <div className="min-w-[52rem] p-5 xl:min-w-0">
                <div className="grid grid-cols-7 gap-2">
                    {dates.map((date) => {
                        const slots = workspace.plan.slots.filter(
                            (slot) => slot.date.slice(0, 10) === date,
                        );

                        return (
                            <section
                                key={date}
                                className="min-h-48 rounded-xl border bg-muted/15 p-2"
                            >
                                <h2 className="mb-2 px-1 text-xs font-semibold">
                                    {formatDay(date)}
                                </h2>
                                <div className="space-y-2">
                                    {slots.map((slot) => (
                                        <SlotCard
                                            key={slot.id}
                                            workspace={workspace}
                                            slot={slot}
                                            emptySlots={emptySlots}
                                            compact
                                        />
                                    ))}
                                    {slots.length === 0 && (
                                        <p className="px-1 py-4 text-center text-[11px] text-muted-foreground">
                                            No meals
                                        </p>
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
                <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                    <CircleAlert className="size-3.5" /> Drag a selected meal to
                    an open slot, or use its Move to menu.
                </p>
            </div>
        </main>
    );
}
