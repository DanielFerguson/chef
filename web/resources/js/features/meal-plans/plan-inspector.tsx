import { router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Check, Copy, MailPlus, Plus } from 'lucide-react';
import { useState } from 'react';
import { store as storeMealSlot } from '@/actions/App/Http/Controllers/MealSlotController';
import PlannedMealMoveController from '@/actions/App/Http/Controllers/PlannedMealMoveController';
import { store as storeInvitation } from '@/actions/App/Http/Controllers/TeamInvitationController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDay } from './format-day';
import { HouseholdTruth } from './household-truth';
import type { MealPlanWorkspace } from './types';

function PlanSlots({ workspace }: { workspace: MealPlanWorkspace }) {
    const { plan, household } = workspace;
    const [showForm, setShowForm] = useState(plan.slots.length === 0);
    const slotForm = useForm({
        date: plan.starts_on.slice(0, 10),
        kind: 'dinner',
        participant_ids: household.people.map((person) => person.id),
    });
    const emptySlots = plan.slots.filter((slot) => slot.planned_meal === null);

    return (
        <section>
            <div className="mb-3 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-sm font-medium">
                    <CalendarDays className="size-4" /> Plan
                </h2>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setShowForm((value) => !value)}
                >
                    <Plus /> Add slot
                </Button>
            </div>
            {showForm && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        slotForm.post(storeMealSlot.url(plan.id), {
                            preserveScroll: true,
                            onSuccess: () => setShowForm(false),
                        });
                    }}
                    className="mb-3 grid grid-cols-[1fr_auto_auto] gap-2"
                >
                    <Input
                        type="date"
                        min={plan.starts_on.slice(0, 10)}
                        max={plan.ends_on.slice(0, 10)}
                        value={slotForm.data.date}
                        onChange={(event) =>
                            slotForm.setData('date', event.target.value)
                        }
                        aria-label="Meal date"
                    />
                    <select
                        value={slotForm.data.kind}
                        onChange={(event) =>
                            slotForm.setData('kind', event.target.value)
                        }
                        className="rounded-md border bg-background px-2 text-sm"
                        aria-label="Meal type"
                    >
                        <option value="breakfast">Breakfast</option>
                        <option value="lunch">Lunch</option>
                        <option value="dinner">Dinner</option>
                        <option value="snack">Snack</option>
                    </select>
                    <Button
                        size="icon"
                        type="submit"
                        data-testid="add-meal-slot"
                        disabled={slotForm.processing}
                    >
                        <Check />
                        <span className="sr-only">Add meal slot</span>
                    </Button>
                    {slotForm.errors.date && (
                        <p
                            role="alert"
                            className="col-span-3 text-xs text-destructive"
                        >
                            {slotForm.errors.date}
                        </p>
                    )}
                </form>
            )}
            <div className="space-y-2">
                {plan.slots.length === 0 && (
                    <p className="rounded-lg border border-dashed p-3 text-xs leading-5 text-muted-foreground">
                        Add the meals you want to cover, or tell Chef in the
                        conversation.
                    </p>
                )}
                {plan.slots.map((slot) => (
                    <div
                        key={slot.id}
                        className="rounded-lg border bg-background p-3"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-medium">
                                {formatDay(slot.date)} ·{' '}
                                {slot.label ?? slot.kind}
                            </p>
                            <span className="text-xs text-muted-foreground">
                                {slot.participants.length} eating
                            </span>
                        </div>
                        {slot.planned_meal ? (
                            <div className="mt-2">
                                <p className="text-sm font-medium">
                                    {slot.planned_meal.title}
                                </p>
                                {slot.planned_meal.summary && (
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {slot.planned_meal.summary}
                                    </p>
                                )}
                                {emptySlots.length > 0 && (
                                    <label className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                        Move to
                                        <select
                                            defaultValue=""
                                            className="min-w-0 flex-1 rounded border bg-background px-2 py-1"
                                            onChange={(event) => {
                                                if (
                                                    event.target.value &&
                                                    slot.planned_meal
                                                ) {
                                                    router.put(
                                                        PlannedMealMoveController.url(
                                                            slot.planned_meal
                                                                .id,
                                                        ),
                                                        {
                                                            meal_slot_id:
                                                                Number(
                                                                    event.target
                                                                        .value,
                                                                ),
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            <option value="" disabled>
                                                Choose slot
                                            </option>
                                            {emptySlots.map((target) => (
                                                <option
                                                    key={target.id}
                                                    value={target.id}
                                                >
                                                    {formatDay(target.date)} ·{' '}
                                                    {target.kind}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Open
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

function InvitationForm({ workspace }: { workspace: MealPlanWorkspace }) {
    const { flash } = usePage().props;
    const unlinkedPeople = workspace.household.people.filter(
        (person) => !person.user_link,
    );
    const form = useForm<{ email: string; person_id: number | '' }>({
        email: '',
        person_id: '',
    });

    return (
        <section>
            <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                <MailPlus className="size-4" /> Plan together
            </h2>
            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeInvitation.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                {unlinkedPeople.length > 0 && (
                    <label className="block text-xs text-muted-foreground">
                        Link invitation to
                        <select
                            aria-label="Link invitation to household member"
                            value={form.data.person_id}
                            onChange={(event) =>
                                form.setData(
                                    'person_id',
                                    event.target.value === ''
                                        ? ''
                                        : Number(event.target.value),
                                )
                            }
                            className="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <option value="">New household member</option>
                            {unlinkedPeople.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <div className="flex gap-2">
                    <Input
                        type="email"
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                        placeholder="name@example.com"
                        aria-label="Invite email"
                        required
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                    >
                        Invite
                    </Button>
                </div>
                {form.errors.email && (
                    <p role="alert" className="text-xs text-destructive">
                        {form.errors.email}
                    </p>
                )}
            </form>
            {flash.invitationUrl && (
                <div className="mt-3 rounded-lg border bg-background p-3">
                    <p className="text-xs font-medium">Invitation ready</p>
                    <p className="mt-1 text-xs break-all text-muted-foreground">
                        {flash.invitationUrl}
                    </p>
                    <Button
                        className="mt-2"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            navigator.clipboard.writeText(
                                flash.invitationUrl ?? '',
                            )
                        }
                    >
                        <Copy /> Copy link
                    </Button>
                </div>
            )}
        </section>
    );
}

export function PlanInspector({ workspace }: { workspace: MealPlanWorkspace }) {
    return (
        <aside className="border-t bg-muted/20 lg:w-96 lg:border-t-0 lg:border-l">
            <div className="space-y-7 p-5">
                <PlanSlots workspace={workspace} />
                <HouseholdTruth household={workspace.household} />
                <InvitationForm workspace={workspace} />
            </div>
        </aside>
    );
}
