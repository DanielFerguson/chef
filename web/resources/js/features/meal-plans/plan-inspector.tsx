import { router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    CircleAlert,
    Copy,
    History,
    MailPlus,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { store as storeMealSlot } from '@/actions/App/Http/Controllers/MealSlotController';
import { store as storeInvitation } from '@/actions/App/Http/Controllers/TeamInvitationController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { HouseholdTruth } from './household-truth';
import type { MealPlanWorkspace } from './types';

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
    conversationId,
    onShowMessageSource,
    className,
}: {
    workspace: MealPlanWorkspace;
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
            </div>
        </aside>
    );
}
