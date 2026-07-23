import { router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    CircleAlert,
    Copy,
    History,
    MailPlus,
    Plus,
    ShoppingBasket,
} from 'lucide-react';
import { useState } from 'react';
import { store as storeMealSlot } from '@/actions/App/Http/Controllers/MealSlotController';
import { store as storeInvitation } from '@/actions/App/Http/Controllers/TeamInvitationController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { HouseholdTruth } from './household-truth';
import type { MealPlanWorkspace, PlanView } from './types';

function money(amount: number | null | undefined, currency = 'AUD') {
    if (amount === null || amount === undefined) {
        return 'Not set';
    }

    return new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amount);
}

function ShoppingStatus({ workspace }: { workspace: MealPlanWorkspace }) {
    const shopping = workspace.shopping;
    const list = shopping?.shopping_list ?? null;
    const budget = shopping?.budget;
    const cart = shopping?.cart_automation;
    const orderRun = shopping?.retailer_order_run;
    const sourceMeals = Array.from(
        new Map(
            (list?.items ?? [])
                .flatMap((item) => item.sources)
                .map((source) => source.planned_meal)
                .filter((meal): meal is NonNullable<typeof meal> => meal !== null)
                .map((meal) => [meal.id, meal] as const),
        ).values(),
    );
    const included =
        list?.items.filter((item) => item.included && !item.in_pantry).length ??
        0;
    const matched =
        list?.items.filter(
            (item) =>
                item.included && !item.in_pantry && item.product_match !== null,
        ).length ?? 0;
    const remaining =
        list?.items.filter(
            (item) =>
                item.included &&
                !item.in_pantry &&
                !item.checked &&
                !item.ordered_at,
        ).length ?? 0;

    if (!shopping || !list) {
        return (
            <section>
                <h2 className="flex items-center gap-2 text-sm font-medium">
                    <ShoppingBasket className="size-4" /> Shopping
                </h2>
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    Start the shopping list from conversation. This inspector
                    fills once Chef has a list to review.
                </p>
            </section>
        );
    }

    return (
        <section className="space-y-5">
            <div>
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="flex items-center gap-2 text-sm font-medium">
                            <ShoppingBasket className="size-4" /> Shopping
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {included}{' '}
                            {included === 1 ? 'item' : 'items'} on the list
                            {remaining > 0 ? ` · ${remaining} remaining` : ''}
                        </p>
                    </div>
                    <Badge
                        variant={
                            list.status === 'completed' ? 'secondary' : 'outline'
                        }
                    >
                        {list.generation_status === 'ready'
                            ? list.status === 'completed'
                                ? 'Completed'
                                : 'Ready'
                            : list.generation_status === 'failed'
                              ? 'Needs retry'
                              : 'Preparing'}
                    </Badge>
                </div>
            </div>

            {budget && (
                <div>
                    <h3 className="text-xs font-medium text-muted-foreground">
                        Budget
                    </h3>
                    <p className="mt-1 text-sm">
                        {money(budget.projected_total, budget.currency)} projected
                        {budget.effective !== null
                            ? ` · ${money(budget.effective, budget.currency)} limit`
                            : ''}
                    </p>
                    {budget.unmatched_items > 0 && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {budget.unmatched_items}{' '}
                            {budget.unmatched_items === 1
                                ? 'item lacks'
                                : 'items lack'}{' '}
                            a price estimate
                        </p>
                    )}
                </div>
            )}

            <div>
                <h3 className="text-xs font-medium text-muted-foreground">
                    Source meals
                </h3>
                {sourceMeals.length === 0 ? (
                    <p className="mt-1 text-xs text-muted-foreground">
                        No meal sources on this list yet.
                    </p>
                ) : (
                    <ul className="mt-2 space-y-1.5">
                        {sourceMeals.slice(0, 8).map((meal) => (
                            <li key={meal.id} className="text-sm">
                                {meal.title}
                            </li>
                        ))}
                        {sourceMeals.length > 8 && (
                            <li className="text-xs text-muted-foreground">
                                +{sourceMeals.length - 8} more
                            </li>
                        )}
                    </ul>
                )}
            </div>

            <div>
                <h3 className="text-xs font-medium text-muted-foreground">
                    Product matches
                </h3>
                <p className="mt-1 text-sm">
                    {matched} of {included} matched
                </p>
            </div>

            {cart && (
                <div>
                    <h3 className="text-xs font-medium text-muted-foreground">
                        Automation
                    </h3>
                    <p className="mt-1 text-sm">
                        {orderRun
                            ? orderRun.status.replaceAll('_', ' ')
                            : cart.ready
                              ? 'Ready to prepare cart'
                              : cart.readiness_reasons[0] ??
                                'Not ready for cart'}
                    </p>
                    {cart.connection && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Retailer {cart.connection.status.replaceAll('_', ' ')}
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

function PlanStatus({ workspace }: { workspace: MealPlanWorkspace }) {
    const { plan, household } = workspace;
    const { readiness } = workspace;
    const [showForm, setShowForm] = useState(plan.slots.length === 0);
    const slotForm = useForm({
        date: plan.starts_on.slice(0, 10),
        kind: 'dinner',
        label: '',
        participant_ids: household.people.map((person) => person.id),
    });
    const confirmed = plan.milestones.some(
        (milestone) => milestone.kind === 'planning_confirmed',
    );

    return (
        <section>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 text-sm font-medium">
                        <CalendarDays className="size-4" /> Plan status
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {plan.slots.length}{' '}
                        {plan.slots.length === 1 ? 'meal slot' : 'meal slots'}
                    </p>
                </div>
                <Badge
                    variant={
                        readiness.safety_review_required
                            ? 'outline'
                            : confirmed
                              ? 'secondary'
                              : 'outline'
                    }
                >
                    {readiness.ready_for_approval
                        ? 'Ready to approve'
                        : readiness.safety_review_required
                          ? 'Safety review'
                          : confirmed
                            ? 'Confirmed'
                            : 'Planning'}
                </Badge>
            </div>
            {plan.derived_data_stale_at && (
                <div className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    <p className="flex items-center gap-2 font-medium">
                        <CircleAlert className="size-3.5" /> Shopping data needs
                        refreshing
                    </p>
                    <p className="mt-1 leading-5">
                        {plan.derived_data_stale_reason}
                    </p>
                </div>
            )}
            <div className="mt-3 flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setShowForm((value) => !value)}
                >
                    <Plus /> Add slot
                </Button>
                {!confirmed && (
                    <Button
                        size="sm"
                        disabled={!readiness.ready_for_approval}
                        title={
                            readiness.ready_for_approval
                                ? 'Approve this draft and prepare shopping'
                                : 'Fill every slot with one clear draft meal before approving'
                        }
                        onClick={() =>
                            router.post(`/meal-plans/${plan.id}/approve`, {
                                explicitly_reviewed_safety: true,
                            })
                        }
                    >
                        <Check /> Approve &amp; prepare
                    </Button>
                )}
                {readiness.ready_for_safety_confirmation && (
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(`/meal-plans/${plan.id}/approve`, {
                                explicitly_reviewed_safety: true,
                            })
                        }
                    >
                        <Check /> Reapprove &amp; prepare
                    </Button>
                )}
            </div>
            {!confirmed && !readiness.ready_for_approval && (
                <p className="mt-2 text-xs text-muted-foreground">
                    {readiness.uncovered_slots > 0 &&
                    readiness.pending_proposals > 0
                        ? `${readiness.pending_proposals} ${readiness.pending_proposals === 1 ? 'suggestion' : 'suggestions'} ready; ${readiness.uncovered_slots} ${readiness.uncovered_slots === 1 ? 'slot still needs' : 'slots still need'} an option.`
                        : readiness.uncovered_slots > 0
                          ? `${readiness.uncovered_slots} ${readiness.uncovered_slots === 1 ? 'slot still needs' : 'slots still need'} an option.`
                          : readiness.pending_proposals > 0
                            ? `${readiness.pending_proposals} ${readiness.pending_proposals === 1 ? 'suggestion needs' : 'suggestions need'} review.`
                            : readiness.safety_review_required
                              ? 'Review the household safety details below before confirming.'
                              : readiness.recipes_unresolved > 0
                                ? 'Finish preparing each cookable recipe before confirming.'
                                : 'Confirm who is eating in every slot before confirming.'}
                </p>
            )}
            {showForm && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        slotForm.post(storeMealSlot.url(plan.id), {
                            preserveScroll: true,
                            onSuccess: () => setShowForm(false),
                        });
                    }}
                    className="mt-3 grid grid-cols-[1fr_auto_auto] gap-2"
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
                        <option value="custom">Custom</option>
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
                    {slotForm.data.kind === 'custom' && (
                        <Input
                            className="col-span-3"
                            aria-label="Custom meal slot label"
                            placeholder="Occasion name"
                            value={slotForm.data.label}
                            onChange={(event) =>
                                slotForm.setData('label', event.target.value)
                            }
                        />
                    )}
                </form>
            )}
            {plan.revisions.length > 0 && (
                <details className="mt-4 border-t pt-3">
                    <summary className="flex cursor-pointer list-none items-center gap-2 text-xs text-muted-foreground">
                        <History className="size-3.5" /> Recent changes
                    </summary>
                    <ol className="mt-3 space-y-2 border-l pl-3 text-xs text-muted-foreground">
                        {plan.revisions.slice(0, 5).map((revision) => (
                            <li key={revision.id}>{revision.summary}</li>
                        ))}
                    </ol>
                </details>
            )}
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

export function PlanInspector({
    workspace,
    view = 'conversation',
    conversationId,
    onShowMessageSource,
    className,
}: {
    workspace: MealPlanWorkspace;
    view?: PlanView;
    conversationId: number;
    onShowMessageSource: (messageId: number) => void;
    className?: string;
}) {
    return (
        <aside
            className={cn(
                'border-t bg-muted/20 lg:h-full lg:w-96 lg:overflow-y-auto lg:border-t-0 lg:border-l',
                className,
            )}
        >
            <div className="space-y-7 p-5">
                {view === 'shopping' ? (
                    <ShoppingStatus workspace={workspace} />
                ) : (
                    <>
                        <PlanStatus workspace={workspace} />
                        <HouseholdTruth
                            household={workspace.household}
                            planId={workspace.plan.id}
                            safetyReviewRequired={
                                workspace.readiness.safety_review_required
                            }
                            conversationId={conversationId}
                            onShowMessageSource={onShowMessageSource}
                        />
                        <InvitationForm workspace={workspace} />
                    </>
                )}
            </div>
        </aside>
    );
}
