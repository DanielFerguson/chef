import { router } from '@inertiajs/react';
import { ArrowRight, ArrowUp, Check, Clock3, Sparkles, X } from 'lucide-react';
import {
    accept,
    reject,
} from '@/actions/App/Http/Controllers/MealProposalDecisionController';
import { Badge } from '@/components/ui/badge';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Button } from '@/components/ui/button';
import { Marker, MarkerContent } from '@/components/ui/marker';
import {
    Message,
    MessageContent,
    MessageHeader,
} from '@/components/ui/message';
import {
    MessageScroller,
    MessageScrollerButton,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerProvider,
    MessageScrollerViewport,
} from '@/components/ui/message-scroller';
import { AssistantMessage } from './assistant-message';
import {
    MessageFeedback,
    PlanningCheckpointFeedback,
} from './conversation-feedback';
import { formatDay } from './format-day';
import type { MealPlanWorkspace } from './types';
import { useChefConversation } from './use-chef-conversation';

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

function PlanNextStep({ workspace }: { workspace: MealPlanWorkspace }) {
    if (workspace.readiness.confirmed) {
        return (
            <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">Planning is complete</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Shopping is next. Chef will turn the confirmed recipes
                        into one traceable list and show any meals that still
                        need ingredients.
                    </p>
                </div>
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            `/meal-plans/${workspace.plan.id}/shopping-list`,
                        )
                    }
                >
                    Start shopping list <ArrowRight />
                </Button>
            </section>
        );
    }

    if (!workspace.readiness.ready_for_confirmation) {
        return null;
    }

    return (
        <section className="mx-auto flex w-full max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-primary/5 px-4 py-3">
            <div>
                <p className="text-sm font-medium">
                    Your plan is ready to confirm
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    All {workspace.readiness.total_slots} meal slots are filled.
                    Shopping comes next.
                </p>
            </div>
            <Button
                size="sm"
                onClick={() =>
                    router.post(
                        `/meal-plans/${workspace.plan.id}/milestones`,
                        { kind: 'planning_confirmed' },
                        { preserveScroll: true },
                    )
                }
            >
                <Check /> Review and confirm
            </Button>
        </section>
    );
}

export function ConversationPanel({
    workspace,
}: {
    workspace: MealPlanWorkspace;
}) {
    const { error, input, messages, sending, sendMessage, setInput } =
        useChefConversation(workspace);
    const hasPendingProposals = workspace.plan.proposals.some(
        (proposal) => proposal.status === 'pending',
    );

    return (
        <main className="flex h-[calc(100svh-7rem)] min-h-0 min-w-0 flex-none flex-col overflow-hidden lg:h-auto lg:flex-1">
            <header className="shrink-0 border-b px-5 py-3 sm:px-8">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-medium">
                            {workspace.plan.title}
                        </h1>
                        <p className="text-xs text-muted-foreground">
                            {formatDay(workspace.plan.starts_on)} –{' '}
                            {formatDay(workspace.plan.ends_on)}
                        </p>
                    </div>
                    <Badge variant="secondary" className="font-normal">
                        {workspace.readiness.confirmed
                            ? 'Confirmed'
                            : workspace.readiness.ready_for_confirmation
                              ? 'Ready to confirm'
                              : 'Planning'}
                    </Badge>
                </div>
            </header>
            <MessageScrollerProvider
                key={messages.at(-1)?.id ?? 'empty-conversation'}
                autoScroll
                defaultScrollPosition="end"
                scrollPreviousItemPeek={56}
            >
                <div className="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col">
                    <MessageScroller className="flex-1">
                        <MessageScrollerViewport className="px-5 sm:px-8">
                            <MessageScrollerContent
                                className="mx-auto w-full max-w-3xl gap-6 py-8 pb-10"
                                aria-busy={sending}
                            >
                                {messages.map((message) => (
                                    <MessageScrollerItem
                                        key={message.id}
                                        messageId={String(message.id)}
                                        scrollAnchor={message.role === 'user'}
                                    >
                                        <Message
                                            align={
                                                message.role === 'user'
                                                    ? 'end'
                                                    : 'start'
                                            }
                                        >
                                            <MessageContent
                                                className={
                                                    message.role === 'user'
                                                        ? 'max-w-[85%]'
                                                        : 'max-w-[92%]'
                                                }
                                            >
                                                <MessageHeader>
                                                    {message.role === 'user'
                                                        ? (message.author
                                                              ?.name ?? 'You')
                                                        : 'Chef'}
                                                </MessageHeader>
                                                {message.role === 'assistant' &&
                                                message.content === '' &&
                                                sending ? (
                                                    <Marker role="status">
                                                        <MarkerContent className="shimmer">
                                                            Thinking…
                                                        </MarkerContent>
                                                    </Marker>
                                                ) : (
                                                    <>
                                                        <Bubble
                                                            variant={
                                                                message.role ===
                                                                'user'
                                                                    ? 'muted'
                                                                    : 'ghost'
                                                            }
                                                            align={
                                                                message.role ===
                                                                'user'
                                                                    ? 'end'
                                                                    : 'start'
                                                            }
                                                        >
                                                            <BubbleContent
                                                                className={
                                                                    message.role ===
                                                                    'user'
                                                                        ? 'whitespace-pre-wrap'
                                                                        : undefined
                                                                }
                                                            >
                                                                {message.role ===
                                                                'user' ? (
                                                                    message.content
                                                                ) : (
                                                                    <AssistantMessage
                                                                        content={
                                                                            message.content
                                                                        }
                                                                    />
                                                                )}
                                                            </BubbleContent>
                                                        </Bubble>
                                                        {message.role ===
                                                            'assistant' &&
                                                            typeof message.id ===
                                                                'number' &&
                                                            message.content !==
                                                                '' && (
                                                                <MessageFeedback
                                                                    messageId={
                                                                        message.id
                                                                    }
                                                                    feedback={
                                                                        message
                                                                            .feedback?.[0]
                                                                    }
                                                                />
                                                            )}
                                                    </>
                                                )}
                                            </MessageContent>
                                        </Message>
                                    </MessageScrollerItem>
                                ))}
                                {hasPendingProposals && (
                                    <MessageScrollerItem
                                        messageId={`proposals-${workspace.plan.revision}`}
                                    >
                                        <ProposalCards workspace={workspace} />
                                    </MessageScrollerItem>
                                )}
                                <MessageScrollerItem messageId="plan-next-step">
                                    <PlanNextStep workspace={workspace} />
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
                        <MessageScrollerButton />
                    </MessageScroller>
                    <form
                        onSubmit={sendMessage}
                        className="relative z-10 mx-5 mb-4 shrink-0 rounded-2xl border bg-card p-3 shadow-lg sm:mx-8"
                    >
                        <textarea
                            value={input}
                            onChange={(event) => setInput(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    event.currentTarget.form?.requestSubmit();
                                }
                            }}
                            aria-label="Message Chef"
                            placeholder="Tell Chef what the plan should account for…"
                            className="min-h-20 w-full resize-none bg-transparent px-2 py-1 text-sm outline-none placeholder:text-muted-foreground"
                        />
                        {error && (
                            <p
                                role="alert"
                                className="px-2 pb-2 text-xs text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        <div className="flex items-center justify-between">
                            <p className="px-2 text-xs text-muted-foreground">
                                Shift + Enter for a new line
                            </p>
                            <Button
                                size="icon"
                                type="submit"
                                data-testid="send-message"
                                disabled={sending || input.trim() === ''}
                            >
                                {sending ? (
                                    <ArrowRight className="animate-pulse" />
                                ) : (
                                    <ArrowUp />
                                )}
                                <span className="sr-only">Send message</span>
                            </Button>
                        </div>
                    </form>
                </div>
            </MessageScrollerProvider>
        </main>
    );
}
