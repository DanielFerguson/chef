import { router } from '@inertiajs/react';
import { ArrowRight, ArrowUp, Check, Clock3, Sparkles, X } from 'lucide-react';
import { useMemo } from 'react';
import {
    accept,
    reject,
} from '@/actions/App/Http/Controllers/MealProposalDecisionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDay } from './format-day';
import type { MealPlanWorkspace } from './types';
import { useChefConversation } from './use-chef-conversation';

function ProposalCards({ workspace }: { workspace: MealPlanWorkspace }) {
    const slotsById = useMemo(
        () => new Map(workspace.plan.slots.map((slot) => [slot.id, slot])),
        [workspace.plan.slots],
    );

    return workspace.plan.proposals
        .filter((proposal) => proposal.status === 'pending')
        .map((proposal) => {
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
                            <p className="text-sm font-medium">
                                {proposal.title}
                            </p>
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

export function ConversationPanel({
    workspace,
}: {
    workspace: MealPlanWorkspace;
}) {
    const { error, input, messages, sending, sendMessage, setInput } =
        useChefConversation(workspace);

    return (
        <main className="flex min-h-[calc(100vh-4rem)] min-w-0 flex-1 flex-col lg:min-h-0">
            <header className="border-b px-5 py-3 sm:px-8">
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
                        Planning
                    </Badge>
                </div>
            </header>
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-5 py-8 sm:px-8">
                <div className="flex-1 space-y-6 pb-10" aria-live="polite">
                    {messages.map((message) => (
                        <article
                            key={message.id}
                            className={
                                message.role === 'user'
                                    ? 'ml-auto max-w-[85%]'
                                    : 'max-w-[92%]'
                            }
                        >
                            <p className="mb-1 text-xs text-muted-foreground">
                                {message.role === 'user'
                                    ? (message.author?.name ?? 'You')
                                    : 'Chef'}
                            </p>
                            <div
                                className={
                                    message.role === 'user'
                                        ? 'rounded-2xl bg-muted px-4 py-3 text-sm leading-6 whitespace-pre-wrap'
                                        : 'text-[15px] leading-7 whitespace-pre-wrap'
                                }
                            >
                                {message.content ||
                                    (sending ? 'Thinking…' : '')}
                            </div>
                        </article>
                    ))}
                    <ProposalCards workspace={workspace} />
                </div>
                <form
                    onSubmit={sendMessage}
                    className="sticky bottom-4 rounded-2xl border bg-card p-3 shadow-lg"
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
                        disabled={sending}
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
        </main>
    );
}
