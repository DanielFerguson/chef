import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    Check,
    Clock3,
    LoaderCircle,
    RefreshCw,
    ShieldCheck,
    ShoppingBasket,
    Sparkles,
    X,
} from 'lucide-react';
import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { FormEvent, RefObject } from 'react';
import { toast } from 'sonner';
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
    useMessageScrollerVisibility,
} from '@/components/ui/message-scroller';
import { cn } from '@/lib/utils';
import { ConversationComposer } from './conversation-composer';
import { PlanningCheckpointFeedback } from './conversation-feedback';
import { ConversationMessageRow } from './conversation-message';
import { formatDay } from './format-day';
import type {
    MealPlanWorkspace,
    Message as ConversationMessage,
} from './types';
import { useChefConversation } from './use-chef-conversation';

const dateKeyFormatters = new Map<string, Intl.DateTimeFormat>();
const dateLabelFormatters = new Map<string, Intl.DateTimeFormat>();

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

function SourceMessageLanding({
    messageId,
    onLanded,
    onMissing,
}: {
    messageId: string;
    onLanded: (messageId: string) => void;
    onMissing: () => void;
}) {
    const { visibleMessageIds } = useMessageScrollerVisibility();
    const landed = useRef(false);

    useEffect(() => {
        const missingTimeout = window.setTimeout(() => {
            if (!landed.current) {
                onMissing();
            }
        }, 2000);

        if (!visibleMessageIds.includes(messageId) || landed.current) {
            return () => window.clearTimeout(missingTimeout);
        }

        landed.current = true;
        const frame = window.requestAnimationFrame(() => {
            const source = document.querySelector<HTMLElement>(
                `[data-message-id="${CSS.escape(messageId)}"]`,
            );

            if (!source) {
                onMissing();

                return;
            }

            source.focus({ preventScroll: true });
            onLanded(messageId);
        });

        return () => {
            window.clearTimeout(missingTimeout);
            window.cancelAnimationFrame(frame);
        };
    }, [messageId, onLanded, onMissing, visibleMessageIds]);

    return null;
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

function PlanNextStep({
    workspace,
    onOpenPlanDetails,
    onOpenShopping,
}: {
    workspace: MealPlanWorkspace;
    onOpenPlanDetails: () => void;
    onOpenShopping: () => void;
}) {
    const shoppingList = workspace.plan.shopping_list;
    const shoppingPreparing =
        shoppingList !== null &&
        shoppingList.generation_status !== 'ready' &&
        shoppingList.generation_status !== 'failed';
    const shoppingFailed =
        shoppingList !== null &&
        (shoppingList.generation_status === 'failed' ||
            shoppingList.generation_failure_code === 'context_changed');

    if (
        workspace.readiness.confirmed &&
        workspace.readiness.recipes_failed > 0
    ) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-destructive/5 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">
                        The completed plan needs another try
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Chef kept every meal choice. Retry the single recipe
                        batch before Chef continues preparing the shop.
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/meal-plans/${workspace.plan.id}/recipes/prepare`,
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
        (workspace.readiness.recipes_preparing > 0 || shoppingPreparing)
    ) {
        const recipesUnresolved = workspace.readiness.recipes_unresolved;

        return (
            <section className="mx-auto w-full max-w-xl rounded-xl bg-primary/5 px-4 py-3">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <LoaderCircle className="size-4 animate-spin" />
                    {workspace.readiness.recipes_preparing > 0
                        ? 'Preparing the completed plan'
                        : 'Preparing your shopping list'}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {workspace.readiness.recipes_preparing > 0
                        ? `Chef is generating ${recipesUnresolved} ${recipesUnresolved === 1 ? 'remaining recipe' : 'remaining recipes'} together from the completed plan. You can keep chatting while the batch finishes.`
                        : 'Chef is combining the confirmed recipes into one traceable list. You can keep chatting while it finishes.'}
                </p>
            </section>
        );
    }

    if (workspace.readiness.confirmed && shoppingFailed) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-destructive/5 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">
                        Shopping list needs another try
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {shoppingList.generation_failure_message ??
                            'Chef could not finish this shopping list. Retry when you are ready.'}
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/meal-plans/${workspace.plan.id}/shopping-list`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <RefreshCw /> Retry shopping list
                </Button>
            </section>
        );
    }

    if (
        workspace.readiness.confirmed &&
        workspace.readiness.ready_for_safety_review &&
        workspace.readiness.safety_review_required
    ) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-500/5 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">
                        Review this plan&rsquo;s safety details
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {workspace.readiness.confirmed
                            ? 'Participants or explicit household constraints changed. Review the current details before reconfirming.'
                            : 'Confirm what the household has reported before confirming this plan. Allergies are never inferred.'}
                    </p>
                </div>
                <Button size="sm" onClick={onOpenPlanDetails}>
                    <ShieldCheck /> Open plan details
                </Button>
            </section>
        );
    }

    if (workspace.readiness.confirmed) {
        const shoppingReady =
            shoppingList !== null && shoppingList.generation_status === 'ready';

        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">Planning is complete</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {shoppingReady
                            ? 'Your traceable shopping list is ready to review and edit with Chef.'
                            : 'Shopping is next. Chef will turn the confirmed recipes into one traceable list.'}
                    </p>
                </div>
                <Button
                    size="sm"
                    onClick={() => {
                        if (shoppingReady) {
                            onOpenShopping();

                            return;
                        }

                        router.post(
                            `/meal-plans/${workspace.plan.id}/shopping-list`,
                            {},
                            { preserveScroll: true },
                        );
                    }}
                >
                    {shoppingReady ? (
                        <>
                            Review shopping list <ArrowRight />
                        </>
                    ) : (
                        <>
                            <ShoppingBasket /> Start shopping list
                        </>
                    )}
                </Button>
            </section>
        );
    }

    if (!workspace.readiness.ready_for_approval) {
        return null;
    }

    const pendingBySlot = new Map<
        number | null,
        (typeof workspace.plan.proposals)[number]
    >();

    for (const proposal of workspace.plan.proposals) {
        if (proposal.status !== 'pending') {
            continue;
        }

        const current = pendingBySlot.get(proposal.meal_slot_id);

        if (current === undefined || proposal.id > current.id) {
            pendingBySlot.set(proposal.meal_slot_id, proposal);
        }
    }

    const draftMeals = workspace.plan.slots.map((slot) => ({
        slot,
        meal: slot.planned_meal ?? pendingBySlot.get(slot.id) ?? null,
    }));

    const safetyConstraints = [
        ...workspace.household.constraints,
        ...workspace.household.people.flatMap((person) => person.constraints),
    ];

    return (
        <section
            data-plan-approval
            className="mx-auto w-full max-w-xl rounded-2xl border bg-card p-4 shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-medium">
                        Your plan is ready to approve
                    </p>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        Review the whole plan once. You can keep chatting to
                        swap anything before approving it.
                    </p>
                </div>
                <span className="shrink-0 rounded-full bg-primary/10 px-2 py-1 text-[11px] font-medium text-primary">
                    {draftMeals.length} meals
                </span>
            </div>
            <ul className="mt-4 divide-y border-y text-sm">
                {draftMeals.map(({ slot, meal }) => (
                    <li
                        key={slot.id}
                        className="grid grid-cols-[5.5rem_1fr] gap-3 py-2.5"
                    >
                        <span className="text-xs text-muted-foreground">
                            {formatDay(slot.date)}
                        </span>
                        <span>
                            <span className="font-medium">
                                {meal?.title ?? 'Open meal'}
                            </span>
                            {meal?.estimated_minutes && (
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {meal.estimated_minutes} min
                                </span>
                            )}
                            {meal?.estimated_cost && (
                                <span className="ml-2 text-xs text-muted-foreground">
                                    ~${meal.estimated_cost.toFixed(2)}
                                </span>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="mt-4 rounded-xl bg-muted/50 px-3 py-2.5 text-xs leading-5">
                <p className="font-medium">Safety check</p>
                <p className="text-muted-foreground">
                    {safetyConstraints.length > 0
                        ? `${safetyConstraints.length} explicit household ${safetyConstraints.length === 1 ? 'rule is' : 'rules are'} included in this plan.`
                        : 'No allergies or safety rules are currently recorded. Approving confirms this is current.'}
                </p>
            </div>
            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                Chef will prepare the recipes, combine the shopping list, match
                routine Woolworths products, and prepare the connected cart. It
                will pause for genuine exceptions and leave checkout to you.
            </p>
            <Button
                className="mt-4 w-full sm:w-auto"
                onClick={() =>
                    router.post(`/meal-plans/${workspace.plan.id}/approve`, {
                        explicitly_reviewed_safety: true,
                    })
                }
            >
                <ShoppingBasket /> Approve plan &amp; prepare shopping
            </Button>
        </section>
    );
}

export type ChefConversationControls = ReturnType<typeof useChefConversation>;

export function ConversationPanel({
    workspace,
    sourceMessageId,
    onSourceMessageShown,
    onOpenPlanDetails,
    onOpenShopping,
    conversation,
}: {
    workspace: MealPlanWorkspace;
    sourceMessageId: number | null;
    onSourceMessageShown: () => void;
    onOpenPlanDetails: () => void;
    onOpenShopping: () => void;
    conversation: ChefConversationControls;
}) {
    const {
        activeClientMessageId,
        error,
        input,
        messages,
        retryMessage,
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
    const sourceHighlightTimeout = useRef<number | null>(null);
    const [sourceLandingMessageId, setSourceLandingMessageId] = useState<
        string | null
    >(null);
    const [sourceHighlightMessageId, setSourceHighlightMessageId] = useState<
        string | null
    >(null);
    const [sourceAnnouncement, setSourceAnnouncement] = useState('');
    const latestMessageId = String(messages.at(-1)?.id ?? '');
    const { scrollToEnd, scrollToMessage } = useMessageScroller();
    const datedConversation = groupMessagesByDate(
        messages,
        workspace.household.timezone,
    );
    const handleSourceLanded = (messageId: string) => {
        setSourceLandingMessageId(null);
        setSourceAnnouncement('Source message shown.');

        if (sourceHighlightTimeout.current !== null) {
            window.clearTimeout(sourceHighlightTimeout.current);
        }

        sourceHighlightTimeout.current = window.setTimeout(() => {
            setSourceHighlightMessageId((current) =>
                current === messageId ? null : current,
            );
            sourceHighlightTimeout.current = null;
        }, 2000);
    };
    const handleSourceMissing = () => {
        setSourceLandingMessageId(null);
        setSourceHighlightMessageId(null);
        setSourceAnnouncement('Source message is no longer available.');
        toast('Source message is no longer available.');
    };

    useEffect(
        () => () => {
            if (sourceHighlightTimeout.current !== null) {
                window.clearTimeout(sourceHighlightTimeout.current);
            }
        },
        [],
    );

    useLayoutEffect(() => {
        const targetMessageId =
            sourceMessageId ??
            (positionedConversationId.current === workspace.conversation.id
                ? null
                : messages.findLast((message) => message.role === 'user')?.id);

        if (targetMessageId === null || targetMessageId === undefined) {
            positionedConversationId.current = workspace.conversation.id;

            return;
        }

        let settledFrame = 0;
        const registrationFrame = window.requestAnimationFrame(() => {
            settledFrame = window.requestAnimationFrame(() => {
                const didScroll = scrollToMessage(String(targetMessageId), {
                    align: sourceMessageId === null ? 'start' : 'center',
                    behavior: sourceMessageId === null ? 'auto' : 'smooth',
                    scrollMargin: sourceMessageId === null ? 56 : 16,
                });

                if (!didScroll) {
                    if (sourceMessageId !== null) {
                        onSourceMessageShown();
                        setSourceLandingMessageId(null);
                        setSourceHighlightMessageId(null);
                        setSourceAnnouncement(
                            'Source message is no longer available.',
                        );
                        toast('Source message is no longer available.');
                    }

                    return;
                }

                positionedConversationId.current = workspace.conversation.id;

                if (sourceMessageId !== null) {
                    const targetId = String(targetMessageId);

                    setSourceAnnouncement('');
                    setSourceHighlightMessageId(targetId);
                    setSourceLandingMessageId(targetId);
                    onSourceMessageShown();
                }
            });
        });

        return () => {
            window.cancelAnimationFrame(registrationFrame);
            window.cancelAnimationFrame(settledFrame);
        };
    }, [
        messages,
        onSourceMessageShown,
        scrollToMessage,
        sourceMessageId,
        workspace.conversation.id,
    ]);

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
            error={error}
            input={input}
            onSubmit={sendConversationMessage}
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
                                const isSourceTarget =
                                    sourceHighlightMessageId === messageId;

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
                                            tabIndex={
                                                isSourceTarget ? -1 : undefined
                                            }
                                            data-source-target={
                                                isSourceTarget
                                                    ? 'true'
                                                    : undefined
                                            }
                                            className={cn(
                                                continuesPrevious && '-mt-4',
                                                isSourceTarget &&
                                                    'rounded-xl bg-primary/5 ring-2 ring-primary/35 transition-[background-color,box-shadow] duration-500 outline-none motion-reduce:transition-none',
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
                            <MessageScrollerItem messageId="plan-next-step">
                                <PlanNextStep
                                    workspace={workspace}
                                    onOpenPlanDetails={onOpenPlanDetails}
                                    onOpenShopping={onOpenShopping}
                                />
                            </MessageScrollerItem>
                            {workspace.plan.planning_confirmed_at && (
                                <MessageScrollerItem messageId="planning-feedback">
                                    <PlanningCheckpointFeedback
                                        conversationId={
                                            workspace.conversation.id
                                        }
                                        feedback={workspace.conversation.feedback.find(
                                            (item) =>
                                                item.context ===
                                                'planning_confirmed',
                                        )}
                                    />
                                </MessageScrollerItem>
                            )}
                        </MessageScrollerContent>
                    </MessageScrollerViewport>
                    {sourceLandingMessageId && (
                        <SourceMessageLanding
                            messageId={sourceLandingMessageId}
                            onLanded={handleSourceLanded}
                            onMissing={handleSourceMissing}
                        />
                    )}
                    {sourceAnnouncement && (
                        <span className="sr-only" role="status">
                            {sourceAnnouncement}
                        </span>
                    )}
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
