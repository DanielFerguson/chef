import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    Check,
    Clock3,
    CircleDollarSign,
    LoaderCircle,
    RefreshCw,
    ShoppingBasket,
    Sparkles,
    UsersRound,
    X,
} from 'lucide-react';
import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode, RefObject } from 'react';
import {
    accept,
    reject,
} from '@/actions/App/Http/Controllers/MealProposalDecisionController';
import { Button } from '@/components/ui/button';
import { Marker, MarkerContent } from '@/components/ui/marker';
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerViewport,
    useMessageScroller,
    useMessageScrollerScrollable,
} from '@/components/ui/message-scroller';
import { ColesConnectionDialog } from '@/features/retailers/coles-connection-dialog';
import { cn } from '@/lib/utils';
import { show as showBasketRun } from '@/routes/basket-runs';
import { approve as approveMealPlan } from '@/routes/meal-plans';
import { prepare as prepareRecipes } from '@/routes/meal-plans/recipes';
import { ConversationComposer } from './conversation-composer';
import { PlanningCheckpointFeedback } from './conversation-feedback';
import { ConversationMessageRow } from './conversation-message';
import { formatDay } from './format-day';
import { SafetyRules } from './household-truth';
import type {
    GroceryPreparation,
    MealPlanWorkspace,
    Message as ConversationMessage,
} from './types';
import type { useChefConversation } from './use-chef-conversation';

const dateKeyFormatters = new Map<string, Intl.DateTimeFormat>();
const dateLabelFormatters = new Map<string, Intl.DateTimeFormat>();
const basketRunTitleByStatus = {
    waiting_for_recipes: 'Preparing recipes',
    waiting_for_connection: 'Connect Coles',
    building_requirements: 'Building the grocery plan',
    discovering_products: 'Finding suitable Coles products',
    selecting_products: 'Choosing the best valid packs',
    preparing_resolution: 'Preparing one plan resolution',
    needs_plan_review: 'Review the revised meal plan',
    revalidating_products: 'Checking stock, packs, and prices',
    products_selected: 'Products selected; basket unchanged',
    replacing_basket: 'Replacing and verifying the Coles basket',
    ready: 'Your Coles basket is ready',
    needs_product: 'Chef needs a valid product',
    reauthentication_required: 'Reconnect Coles',
    failed: 'Basket preparation stopped',
    uncertain: 'The Coles basket needs review',
    restoring: 'Restoring the previous basket',
    restored: 'The previous basket was restored',
    needs_attention: 'The Coles basket needs attention',
    cancelled: 'Basket preparation was cancelled',
} satisfies Record<NonNullable<GroceryPreparation['run']>['status'], string>;

function dateKeyFormatter(timeZone: string) {
    let formatter = dateKeyFormatters.get(timeZone);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });
        dateKeyFormatters.set(timeZone, formatter);
    }

    return formatter;
}

function dateLabelFormatter(timeZone: string, includeYear: boolean) {
    const key = `${timeZone}:${includeYear ? 'year' : 'no-year'}`;
    let formatter = dateLabelFormatters.get(key);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat('en-AU', {
            timeZone,
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: includeYear ? 'numeric' : undefined,
        });
        dateLabelFormatters.set(key, formatter);
    }

    return formatter;
}

function messagesShareSender(
    previous: ConversationMessage | undefined,
    current: ConversationMessage | undefined,
) {
    return (
        previous !== undefined &&
        current !== undefined &&
        previous.role === current.role &&
        (current.role === 'assistant' ||
            previous.author?.id === current.author?.id)
    );
}

function zonedDateKey(date: Date, timeZone: string) {
    const parts = dateKeyFormatter(timeZone).formatToParts(date);
    const value = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${value('year')}-${value('month')}-${value('day')}`;
}

function previousDateKey(dateKey: string) {
    const [year, month, day] = dateKey.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day) - 86_400_000)
        .toISOString()
        .slice(0, 10);
}

function formatConversationDateLabel(
    date: Date,
    dateKey: string,
    timeZone: string,
) {
    const todayKey = zonedDateKey(new Date(), timeZone);

    if (dateKey === todayKey) {
        return 'Today';
    }

    if (dateKey === previousDateKey(todayKey)) {
        return 'Yesterday';
    }

    return dateLabelFormatter(
        timeZone,
        dateKey.slice(0, 4) !== todayKey.slice(0, 4),
    ).format(date);
}

function groupMessagesByDate(
    messages: ConversationMessage[],
    timeZone: string,
) {
    let currentDate = new Date();
    let currentDateKey = zonedDateKey(currentDate, timeZone);
    const entries = messages.map((message) => {
        if (message.created_at) {
            const messageDate = new Date(message.created_at);

            if (!Number.isNaN(messageDate.getTime())) {
                currentDate = messageDate;
                currentDateKey = zonedDateKey(messageDate, timeZone);
            }
        }

        return {
            message,
            date: currentDate,
            dateKey: currentDateKey,
        };
    });

    return {
        entries,
        showDateMarkers:
            new Set(entries.map((entry) => entry.dateKey)).size > 1,
    };
}

function LatestMessageControl({
    latestMessageId,
    sending,
    viewportRef,
}: {
    latestMessageId: string;
    sending: boolean;
    viewportRef: RefObject<HTMLDivElement | null>;
}) {
    const { end } = useMessageScrollerScrollable();
    const [messageStatus, setMessageStatus] = useState({
        latestMessageId,
        unseen: false,
    });
    const [restoreTranscriptFocus, setRestoreTranscriptFocus] = useState(false);

    if (messageStatus.latestMessageId !== latestMessageId) {
        setMessageStatus({ latestMessageId, unseen: end });
    } else if (!end && messageStatus.unseen) {
        setMessageStatus({ latestMessageId, unseen: false });
    }

    const hasUnseenResponse = end && messageStatus.unseen;
    const label = sending
        ? 'Chef is replying · Jump to latest'
        : hasUnseenResponse
          ? 'New response · Jump to latest'
          : 'Jump to latest';

    useEffect(() => {
        if (end || !restoreTranscriptFocus) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            viewportRef.current?.focus({ preventScroll: true });
            setRestoreTranscriptFocus(false);
        });

        return () => window.cancelAnimationFrame(frame);
    }, [end, restoreTranscriptFocus, viewportRef]);

    return (
        <MessageScrollerButton
            variant="outline"
            size="sm"
            className="bottom-3 h-9 max-w-[calc(100%-2rem)] gap-2 rounded-full px-3 shadow-md"
            data-testid="jump-to-latest"
            aria-label={label.replace(' · ', '. ')}
            onClick={() => {
                setMessageStatus({ latestMessageId, unseen: false });
                setRestoreTranscriptFocus(true);
            }}
        >
            {sending ? (
                <LoaderCircle className="animate-spin" />
            ) : (
                <ArrowDown />
            )}
            <span aria-live="polite">{label}</span>
        </MessageScrollerButton>
    );
}

function ProposalCards({ workspace }: { workspace: MealPlanWorkspace }) {
    const slotsById = new Map(
        workspace.plan.slots.map((slot) => [slot.id, slot]),
    );

    return workspace.plan.proposals.map((proposal) => {
        if (proposal.status !== 'pending') {
            return null;
        }

        const slot = proposal.meal_slot_id
            ? slotsById.get(proposal.meal_slot_id)
            : null;

        return (
            <section
                key={proposal.id}
                className="max-w-xl rounded-xl border bg-card p-4 shadow-sm"
            >
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 rounded-md bg-primary/10 p-2 text-primary">
                        <Sparkles className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">{proposal.title}</p>
                        {proposal.summary && (
                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                {proposal.summary}
                            </p>
                        )}
                        <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                            {slot && (
                                <span>
                                    {formatDay(slot.date)} · {slot.kind}
                                </span>
                            )}
                            {proposal.estimated_minutes && (
                                <span className="flex items-center gap-1">
                                    <Clock3 className="size-3" />{' '}
                                    {proposal.estimated_minutes} min
                                </span>
                            )}
                            {proposal.estimated_cost && (
                                <span data-numeric="tabular">
                                    ~${proposal.estimated_cost.toFixed(2)}
                                </span>
                            )}
                        </div>
                        <div className="mt-4 flex gap-2">
                            <Button
                                size="sm"
                                disabled={!proposal.meal_slot_id}
                                onClick={() =>
                                    router.put(
                                        accept.url(proposal.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Check />{' '}
                                {slot?.planned_meal ? 'Replace' : 'Accept'}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.put(
                                        reject.url(proposal.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <X /> Reject
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        );
    });
}

function PlanStateCopy({
    children,
    icon,
    title,
}: {
    children: ReactNode;
    icon?: ReactNode;
    title: ReactNode;
}) {
    return (
        <div className="typeset typeset-docs max-w-[37em]" data-plan-state-copy>
            <p className={cn('font-medium', icon && 'flex items-center gap-2')}>
                {icon}
                {title}
            </p>
            <p className="mt-0.5 text-muted-foreground">{children}</p>
        </div>
    );
}

function BasketRunNextStep({
    basketRun,
}: {
    basketRun: NonNullable<GroceryPreparation['run']>;
}) {
    const active = basketRun.public_state === 'preparing';
    const total =
        basketRun.retailer_total_cents ?? basketRun.chef_subtotal_cents;
    const title = active
        ? 'Preparing your Coles basket'
        : basketRun.public_state === 'plan_review_required'
          ? 'Review one revised meal plan'
          : basketRun.public_outcome === 'basket_ready'
            ? 'Your Coles basket is ready'
            : basketRunTitleByStatus[basketRun.status];
    const description = active
        ? 'You can leave this page. Chef is preparing and verifying the basket in the background; there is nothing else to do right now.'
        : basketRun.public_state === 'plan_review_required'
          ? basketRun.attention_kind === 'budget_overrun'
              ? 'The valid products exceed your basket target. Choose this basket or review Chef’s single cheaper-plan proposal.'
              : 'A required product was unavailable. Chef prepared one coherent plan diff for your approval; the existing Coles basket is unchanged.'
          : basketRun.failure_message
            ? basketRun.failure_message
            : basketRun.public_outcome === 'basket_ready'
              ? `Chef verified the basket${total === null ? '.' : ` · $${(total / 100).toFixed(2)} captured total.`} Prices remain estimates until checkout.`
              : 'Open the basket details to review the confirmed state and available next steps.';

    return (
        <section
            className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3"
            aria-live="polite"
        >
            <PlanStateCopy
                title={title}
                icon={
                    active ? (
                        <LoaderCircle className="size-4 animate-spin" />
                    ) : basketRun.public_outcome === 'basket_ready' ? (
                        <Check className="size-4" />
                    ) : undefined
                }
            >
                {description}
            </PlanStateCopy>
            <Button asChild size="sm" variant="outline">
                <Link href={showBasketRun(basketRun.id)}>
                    View basket <ArrowRight />
                </Link>
            </Button>
        </section>
    );
}

function PlanApprovalCard({
    workspace,
    conversation,
}: {
    workspace: MealPlanWorkspace;
    conversation: ChefConversationControls;
}) {
    const brief = workspace.approval_brief;
    const grocery = workspace.grocery_preparation;
    const pendingApproval = workspace.pending_tool_approval;

    return (
        <section
            data-plan-approval
            className="mx-auto w-full max-w-xl rounded-2xl border bg-card p-4 shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <PlanStateCopy title="Your plan is ready to approve">
                    {pendingApproval
                        ? 'Chef is waiting for your decision. Review the whole plan once, then approve it or keep editing.'
                        : 'Review the whole plan once. You can keep chatting to swap anything before approving it.'}
                </PlanStateCopy>
                <span className="shrink-0 rounded-full bg-muted px-2 py-1 text-[11px] font-medium text-foreground">
                    {brief.meal_count}{' '}
                    {brief.meal_count === 1 ? 'meal' : 'meals'}
                </span>
            </div>
            <dl className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted-foreground">
                <div>
                    <dt className="sr-only">Estimated time</dt>
                    <dd className="flex items-center gap-1.5">
                        <Clock3 aria-hidden="true" className="size-3.5" />
                        {brief.estimated_minutes} min total
                    </dd>
                </div>
                <div>
                    <dt className="sr-only">Estimated meal cost</dt>
                    <dd className="flex items-center gap-1.5">
                        <CircleDollarSign
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        ~${(brief.estimated_cost_cents / 100).toFixed(2)} meal
                        cost
                    </dd>
                </div>
                <div>
                    <dt className="sr-only">Basket target</dt>
                    <dd className="flex items-center gap-1.5">
                        <ShoppingBasket
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        {brief.purchase_policy.basket_target_cents === null
                            ? 'No basket target'
                            : `$${(
                                  brief.purchase_policy.basket_target_cents /
                                  100
                              ).toFixed(2)} basket target`}
                    </dd>
                </div>
            </dl>
            <ul className="mt-4 divide-y border-y text-sm">
                {brief.meals.map((meal) => (
                    <li
                        key={meal.meal_slot_id}
                        className="grid gap-1 py-3 sm:grid-cols-[6rem_1fr] sm:gap-3"
                    >
                        <span className="text-xs text-muted-foreground">
                            {formatDay(meal.date)}
                        </span>
                        <span className="min-w-0">
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {meal.title ?? 'Open meal'}
                                </span>
                                {meal.is_replacement && (
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-foreground">
                                        Replacement
                                    </span>
                                )}
                            </span>
                            <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1">
                                    <UsersRound className="size-3" />
                                    {meal.participants
                                        .map(
                                            (participant) =>
                                                `${participant.name} (${participant.servings})`,
                                        )
                                        .join(', ')}
                                </span>
                                {meal.estimated_minutes !== null && (
                                    <span>{meal.estimated_minutes} min</span>
                                )}
                                {meal.estimated_cost_cents !== null && (
                                    <span>
                                        ~$
                                        {(
                                            meal.estimated_cost_cents / 100
                                        ).toFixed(2)}
                                    </span>
                                )}
                            </span>
                            {meal.participant_default.provisional && (
                                <span className="mt-1 block text-[11px] text-amber-700 dark:text-amber-300">
                                    Suggested servings · edit before approval if
                                    needed
                                </span>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="typeset typeset-docs mt-3 space-y-1 text-muted-foreground">
                <p>
                    {brief.grocery_preparation.effect} Checkout stays with you.
                </p>
                <p>
                    Grocery policy:{' '}
                    {brief.purchase_policy.home_brand_preference} home brand,{' '}
                    {brief.purchase_policy.bulk_preference} bulk, and{' '}
                    {brief.purchase_policy.organic_preference.replace('_', ' ')}{' '}
                    organic.
                </p>
                {brief.safety.constraints.length > 0 && (
                    <p>
                        {brief.safety.constraints.length} explicit safety{' '}
                        {brief.safety.constraints.length === 1
                            ? 'constraint is'
                            : 'constraints are'}{' '}
                        retained; Chef has not inferred any.
                    </p>
                )}
            </div>
            <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                <Button
                    className="w-full sm:w-auto"
                    disabled={conversation.sending}
                    onClick={() => {
                        if (pendingApproval) {
                            void conversation.resolveToolApproval(
                                pendingApproval,
                                'approve',
                            );

                            return;
                        }

                        router.post(approveMealPlan.url(workspace.plan.id));
                    }}
                >
                    {conversation.sending && pendingApproval ? (
                        <LoaderCircle className="animate-spin" />
                    ) : (
                        <Sparkles />
                    )}{' '}
                    {pendingApproval
                        ? 'Approve plan'
                        : (grocery.approval_label ??
                          'Approve plan & prepare recipes')}
                </Button>
                {pendingApproval && (
                    <Button
                        className="w-full sm:w-auto"
                        variant="outline"
                        disabled={conversation.sending}
                        onClick={() =>
                            void conversation.resolveToolApproval(
                                pendingApproval,
                                'reject',
                            )
                        }
                    >
                        Keep editing
                    </Button>
                )}
            </div>
        </section>
    );
}

function PlanNextStep({
    workspace,
    conversation,
}: {
    workspace: MealPlanWorkspace;
    conversation: ChefConversationControls;
}) {
    const [connectionOpen, setConnectionOpen] = useState(false);
    const grocery = workspace.grocery_preparation;
    const basketRun = grocery.enabled ? (grocery.run ?? null) : null;
    const needsConnection = basketRun?.public_state === 'connection_required';

    if (needsConnection && basketRun !== null) {
        const canConnect =
            grocery.can_connect === true &&
            (grocery.connection?.owned_by_current_user ?? true);
        const isReauthentication =
            basketRun.status === 'reauthentication_required';

        return (
            <>
                <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 shadow-sm">
                    <PlanStateCopy
                        title={
                            isReauthentication
                                ? 'Continue with Coles'
                                : 'Connect Coles to continue'
                        }
                    >
                        {canConnect
                            ? isReauthentication
                                ? 'Your saved consent remains in place. Continue the Coles sign-in and Chef will resume this basket automatically.'
                                : 'Recipes are preparing in the background. Sign in as the Coles account owner, review the disclosure, and Chef will resume this basket automatically.'
                            : 'The household member who owns the connected Coles account needs to sign in before Chef can continue.'}
                    </PlanStateCopy>
                    <Button
                        data-testid="connect-coles"
                        size="sm"
                        disabled={!canConnect}
                        onClick={() => setConnectionOpen(true)}
                    >
                        <ShoppingBasket />{' '}
                        {isReauthentication
                            ? 'Continue with Coles'
                            : 'Connect Coles'}
                    </Button>
                </section>
                <ColesConnectionDialog
                    groceryPreparation={grocery}
                    open={connectionOpen}
                    onOpenChange={setConnectionOpen}
                />
            </>
        );
    }

    if (
        workspace.readiness.confirmed &&
        workspace.readiness.recipes_failed > 0
    ) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-destructive/5 px-4 py-3">
                <PlanStateCopy title="The completed plan needs another try">
                    Chef kept every meal choice. Retry the single recipe batch
                    to finish preparing the plan.
                </PlanStateCopy>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            prepareRecipes.url(workspace.plan.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <RefreshCw /> Retry recipes
                </Button>
            </section>
        );
    }

    if (
        workspace.readiness.confirmed &&
        workspace.readiness.recipes_preparing > 0
    ) {
        const recipesUnresolved = workspace.readiness.recipes_unresolved;

        return (
            <section className="mx-auto w-full max-w-xl rounded-xl bg-primary/5 px-4 py-3">
                <PlanStateCopy
                    title="Preparing the completed plan"
                    icon={<LoaderCircle className="size-4 animate-spin" />}
                >
                    Chef is generating {recipesUnresolved}{' '}
                    {recipesUnresolved === 1
                        ? 'remaining recipe'
                        : 'remaining recipes'}{' '}
                    together from the completed plan. You can keep chatting
                    while the batch finishes.
                </PlanStateCopy>
            </section>
        );
    }

    if (
        workspace.readiness.confirmed &&
        workspace.readiness.ready_for_safety_confirmation
    ) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3">
                <PlanStateCopy title="Plan details changed">
                    Participants or plan details changed. Reapprove to refresh
                    recipe preparation.
                </PlanStateCopy>
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(approveMealPlan.url(workspace.plan.id))
                    }
                >
                    <RefreshCw /> Reapprove &amp; prepare recipes
                </Button>
            </section>
        );
    }

    if (
        workspace.readiness.confirmed &&
        workspace.readiness.recipes_unresolved > 0
    ) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3">
                <PlanStateCopy title="Recipes are ready to prepare">
                    Start one batch for the remaining recipes in this approved
                    plan.
                </PlanStateCopy>
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            prepareRecipes.url(workspace.plan.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <Sparkles /> Prepare recipes
                </Button>
            </section>
        );
    }

    if (basketRun !== null) {
        return <BasketRunNextStep basketRun={basketRun} />;
    }

    if (workspace.readiness.confirmed) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3">
                <PlanStateCopy title="Plan and recipes are ready">
                    Your household can review the recipes now and return here
                    whenever the plan needs to change.
                </PlanStateCopy>
                <Button size="sm" onClick={() => router.get('/recipes')}>
                    View recipes <ArrowRight />
                </Button>
            </section>
        );
    }

    if (!workspace.readiness.ready_for_approval) {
        return null;
    }

    return (
        <PlanApprovalCard workspace={workspace} conversation={conversation} />
    );
}

export type ChefConversationControls = ReturnType<typeof useChefConversation>;

export function ConversationPanel({
    workspace,
    conversation,
}: {
    workspace: MealPlanWorkspace;
    conversation: ChefConversationControls;
}) {
    const {
        activeClientMessageId,
        addPhotos,
        error,
        input,
        messages,
        retryMessage,
        removePhoto,
        selectedPhotos,
        sendPhase,
        sending,
        sendMessage,
        setInput,
    } = conversation;

    const hasPendingProposals =
        !workspace.readiness.ready_for_approval &&
        workspace.plan.proposals.some(
            (proposal) => proposal.status === 'pending',
        );
    const viewportRef = useRef<HTMLDivElement>(null);
    const positionedConversationId = useRef<number | null>(null);
    const latestMessageId = String(messages.at(-1)?.id ?? '');
    const { scrollToEnd, scrollToMessage } = useMessageScroller();
    const datedConversation = groupMessagesByDate(
        messages,
        workspace.household.timezone,
    );
    useLayoutEffect(() => {
        const targetMessageId =
            positionedConversationId.current === workspace.conversation.id
                ? null
                : messages.findLast((message) => message.role === 'user')?.id;

        if (targetMessageId === null || targetMessageId === undefined) {
            positionedConversationId.current = workspace.conversation.id;

            return;
        }

        let settledFrame = 0;
        const registrationFrame = window.requestAnimationFrame(() => {
            settledFrame = window.requestAnimationFrame(() => {
                const didScroll = scrollToMessage(String(targetMessageId), {
                    align: 'start',
                    behavior: 'auto',
                    scrollMargin: 56,
                });

                if (didScroll) {
                    positionedConversationId.current =
                        workspace.conversation.id;
                }
            });
        });

        return () => {
            window.cancelAnimationFrame(registrationFrame);
            window.cancelAnimationFrame(settledFrame);
        };
    }, [messages, scrollToMessage, workspace.conversation.id]);

    const sendConversationMessage = (event: FormEvent<HTMLFormElement>) => {
        scrollToEnd({ behavior: 'auto' });
        void sendMessage(event);
    };

    const retryConversationMessage = (message: ConversationMessage) => {
        scrollToEnd({ behavior: 'smooth' });

        return retryMessage(message);
    };

    const composer = (
        <ConversationComposer
            addPhotos={addPhotos}
            error={error}
            input={input}
            onSubmit={sendConversationMessage}
            removePhoto={removePhoto}
            selectedPhotos={selectedPhotos}
            sendPhase={sendPhase}
            sending={sending}
            setInput={setInput}
        />
    );

    return (
        <main className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            <div className="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col">
                <MessageScroller className="flex-1">
                    <MessageScrollerViewport
                        ref={viewportRef}
                        aria-label="Meal planning conversation"
                        className="px-5 sm:px-8"
                    >
                        <MessageScrollerContent
                            className="mx-auto w-full max-w-3xl gap-6 py-8 pb-10"
                            aria-busy={sending}
                        >
                            {datedConversation.entries.map((entry, index) => {
                                const { date, dateKey, message } = entry;
                                const previousEntry =
                                    datedConversation.entries[index - 1];
                                const nextEntry =
                                    datedConversation.entries[index + 1];
                                const startsNewDate =
                                    previousEntry?.dateKey !== dateKey;
                                const continuesPrevious =
                                    previousEntry?.dateKey === dateKey &&
                                    messagesShareSender(
                                        previousEntry.message,
                                        message,
                                    );
                                const continuesNext =
                                    nextEntry?.dateKey === dateKey &&
                                    messagesShareSender(
                                        message,
                                        nextEntry.message,
                                    );
                                const messageId = String(message.id);

                                return (
                                    <Fragment key={message.id}>
                                        {datedConversation.showDateMarkers &&
                                            startsNewDate && (
                                                <MessageScrollerItem
                                                    messageId={`date-${dateKey}`}
                                                    className="py-1"
                                                >
                                                    <Marker variant="separator">
                                                        <MarkerContent className="text-xs">
                                                            {formatConversationDateLabel(
                                                                date,
                                                                dateKey,
                                                                workspace
                                                                    .household
                                                                    .timezone,
                                                            )}
                                                        </MarkerContent>
                                                    </Marker>
                                                </MessageScrollerItem>
                                            )}
                                        <MessageScrollerItem
                                            messageId={messageId}
                                            scrollAnchor={
                                                message.role === 'user' &&
                                                message.client_message_id !==
                                                    activeClientMessageId
                                            }
                                            className={cn(
                                                continuesPrevious && '-mt-4',
                                            )}
                                        >
                                            <ConversationMessageRow
                                                message={message}
                                                retryMessage={
                                                    retryConversationMessage
                                                }
                                                sending={sending}
                                                showAvatar={!continuesNext}
                                                showHeader={!continuesPrevious}
                                            />
                                        </MessageScrollerItem>
                                    </Fragment>
                                );
                            })}
                            {hasPendingProposals && (
                                <MessageScrollerItem
                                    messageId={`proposals-${workspace.plan.revision}`}
                                >
                                    <ProposalCards workspace={workspace} />
                                </MessageScrollerItem>
                            )}
                            <MessageScrollerItem messageId="household-safety">
                                <SafetyRules
                                    household={workspace.household}
                                    planId={workspace.plan.id}
                                    safetyReviewRequired={
                                        workspace.readiness
                                            .ready_for_safety_review &&
                                        !workspace.readiness.safety_reviewed
                                    }
                                    conversationId={workspace.conversation.id}
                                    onShowMessageSource={(messageId) => {
                                        scrollToMessage(String(messageId), {
                                            align: 'start',
                                            behavior: 'smooth',
                                            scrollMargin: 56,
                                        });
                                    }}
                                />
                            </MessageScrollerItem>
                            <MessageScrollerItem messageId="plan-next-step">
                                <PlanNextStep
                                    workspace={workspace}
                                    conversation={conversation}
                                />
                            </MessageScrollerItem>
                            {workspace.plan.planning_confirmed_at && (
                                <PlanningCheckpointFeedback
                                    conversationId={workspace.conversation.id}
                                    feedback={workspace.conversation.feedback.find(
                                        (item) =>
                                            item.context ===
                                            'planning_confirmed',
                                    )}
                                />
                            )}
                        </MessageScrollerContent>
                    </MessageScrollerViewport>
                    <LatestMessageControl
                        latestMessageId={latestMessageId}
                        sending={sending}
                        viewportRef={viewportRef}
                    />
                </MessageScroller>
                {composer}
            </div>
        </main>
    );
}
