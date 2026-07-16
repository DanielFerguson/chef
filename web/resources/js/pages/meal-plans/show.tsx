import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUp,
    CalendarDays,
    Check,
    Clock3,
    Copy,
    MailPlus,
    Pencil,
    Plus,
    ShieldCheck,
    Sparkles,
    Trash2,
    UsersRound,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Person = {
    id: number;
    name: string;
    preferences: Preference[];
    constraints: Constraint[];
};

type Preference = {
    id: number;
    person_id: number | null;
    subject: string;
    sentiment: 'like' | 'dislike';
    strength: number;
    provenance: 'stated' | 'default' | 'inferred' | 'feedback';
};

type Constraint = {
    id: number;
    person_id: number | null;
    kind: string;
    subject: string;
    details: string | null;
    severity: string | null;
};

type PlannedMeal = {
    id: number;
    meal_slot_id: number;
    title: string;
    summary: string | null;
    estimated_minutes: number | null;
    estimated_cost: number | null;
};

type MealSlot = {
    id: number;
    date: string;
    kind: string;
    label: string | null;
    participants: Person[];
    planned_meal: PlannedMeal | null;
};

type MealProposal = {
    id: number;
    meal_slot_id: number | null;
    title: string;
    summary: string | null;
    estimated_minutes: number | null;
    estimated_cost: number | null;
    status: 'pending' | 'accepted' | 'rejected' | 'replaced';
};

type Message = {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    author?: { id: number; name: string } | null;
};

type Workspace = {
    plan: {
        id: number;
        title: string;
        starts_on: string;
        ends_on: string;
        slots: MealSlot[];
        proposals: MealProposal[];
    };
    conversation: {
        id: number;
        messages: Message[];
    };
    household: {
        id: number;
        name: string;
        people: Person[];
        preferences: Preference[];
        constraints: Constraint[];
    };
};

type StreamEvent = {
    type: 'delta' | 'complete' | 'persisted' | 'error';
    delta?: string;
    message?: string;
};

const formatDay = (date: string) =>
    new Intl.DateTimeFormat('en-AU', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(new Date(`${date.slice(0, 10)}T00:00:00Z`));

function HouseholdTruthGroup({
    title,
    empty,
    children,
}: {
    title: string;
    empty: string;
    children: ReactNode;
}) {
    const items = Array.isArray(children) ? children.filter(Boolean) : children;
    const hasItems = Array.isArray(items) ? items.length > 0 : Boolean(items);

    return (
        <div>
            <p className="text-xs font-medium text-muted-foreground">{title}</p>
            {hasItems ? (
                <ul>{items}</ul>
            ) : (
                <p className="py-1.5 text-xs text-muted-foreground">{empty}</p>
            )}
        </div>
    );
}

function EditablePreference({
    preference,
    owner,
}: {
    preference: Preference;
    owner: string;
}) {
    const [editing, setEditing] = useState(false);
    const [subject, setSubject] = useState(preference.subject);

    const save = () => {
        router.put(
            `/preferences/${preference.id}`,
            {
                subject,
                sentiment: preference.sentiment,
                strength: preference.strength,
            },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    };

    return (
        <li className="flex items-center gap-2 py-1.5 text-sm">
            {editing ? (
                <>
                    <Input
                        value={subject}
                        onChange={(event) => setSubject(event.target.value)}
                        className="h-8"
                        aria-label="Preference"
                    />
                    <Button size="icon" variant="ghost" onClick={save}>
                        <Check />
                        <span className="sr-only">Save preference</span>
                    </Button>
                </>
            ) : (
                <>
                    <span className="min-w-0 flex-1 truncate">
                        <span className="text-muted-foreground">{owner}: </span>
                        {preference.sentiment === 'dislike'
                            ? 'Avoid '
                            : 'Likes '}
                        {preference.subject}
                    </span>
                    <Badge variant="outline" className="font-normal">
                        {preference.provenance}
                    </Badge>
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        <span className="sr-only">Edit preference</span>
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={() =>
                            router.delete(`/preferences/${preference.id}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 />
                        <span className="sr-only">Delete preference</span>
                    </Button>
                </>
            )}
        </li>
    );
}

function EditableConstraint({
    constraint,
    owner,
}: {
    constraint: Constraint;
    owner: string;
}) {
    const [editing, setEditing] = useState(false);
    const [subject, setSubject] = useState(constraint.subject);

    const save = () => {
        router.put(
            `/constraints/${constraint.id}`,
            {
                subject,
                details: constraint.details,
                severity: constraint.severity,
                explicitly_confirmed: true,
            },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    };

    return (
        <li className="flex items-center gap-2 py-1.5 text-sm">
            <ShieldCheck className="size-4 shrink-0 text-primary" />
            {editing ? (
                <>
                    <Input
                        value={subject}
                        onChange={(event) => setSubject(event.target.value)}
                        className="h-8"
                    />
                    <Button size="icon" variant="ghost" onClick={save}>
                        <Check />
                        <span className="sr-only">Save constraint</span>
                    </Button>
                </>
            ) : (
                <>
                    <span className="min-w-0 flex-1 truncate">
                        <span className="text-muted-foreground">{owner}: </span>
                        {constraint.subject}
                    </span>
                    <Badge variant="outline" className="font-normal">
                        {constraint.kind}
                    </Badge>
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        <span className="sr-only">Edit constraint</span>
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={() =>
                            router.delete(`/constraints/${constraint.id}`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 />
                        <span className="sr-only">Delete constraint</span>
                    </Button>
                </>
            )}
        </li>
    );
}

function PlanInspector({ workspace }: { workspace: Workspace }) {
    const { flash } = usePage().props;
    const { plan, household } = workspace;
    const [showSlotForm, setShowSlotForm] = useState(plan.slots.length === 0);
    const [slotDate, setSlotDate] = useState(plan.starts_on.slice(0, 10));
    const [slotKind, setSlotKind] = useState('dinner');
    const [inviteEmail, setInviteEmail] = useState('');
    const emptySlots = plan.slots.filter((slot) => slot.planned_meal === null);
    const ownerNames = new Map(
        household.people.map((person) => [person.id, person.name]),
    );
    const preferences = [
        ...household.preferences,
        ...household.people.flatMap((person) => person.preferences),
    ];
    const constraints = [
        ...household.constraints,
        ...household.people.flatMap((person) => person.constraints),
    ];
    const ownerFor = (personId: number | null) =>
        personId === null
            ? household.name
            : (ownerNames.get(personId) ?? 'Household member');

    const addSlot = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            `/meal-plans/${plan.id}/slots`,
            {
                date: slotDate,
                kind: slotKind,
                participant_ids: household.people.map((person) => person.id),
            },
            { preserveScroll: true, onSuccess: () => setShowSlotForm(false) },
        );
    };

    return (
        <aside className="border-t bg-muted/20 lg:w-96 lg:border-t-0 lg:border-l">
            <div className="space-y-7 p-5">
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-sm font-medium">
                            <CalendarDays className="size-4" /> Plan
                        </h2>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setShowSlotForm((value) => !value)}
                        >
                            <Plus /> Add slot
                        </Button>
                    </div>
                    {showSlotForm && (
                        <form
                            onSubmit={addSlot}
                            className="mb-3 grid grid-cols-[1fr_auto_auto] gap-2"
                        >
                            <Input
                                type="date"
                                min={plan.starts_on.slice(0, 10)}
                                max={plan.ends_on.slice(0, 10)}
                                value={slotDate}
                                onChange={(event) =>
                                    setSlotDate(event.target.value)
                                }
                                aria-label="Meal date"
                            />
                            <select
                                value={slotKind}
                                onChange={(event) =>
                                    setSlotKind(event.target.value)
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
                            >
                                <Check />
                                <span className="sr-only">Add meal slot</span>
                            </Button>
                        </form>
                    )}
                    <div className="space-y-2">
                        {plan.slots.length === 0 && (
                            <p className="rounded-lg border border-dashed p-3 text-xs leading-5 text-muted-foreground">
                                Add the meals you want to cover, or tell Chef in
                                the conversation.
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
                                                            event.target.value
                                                        ) {
                                                            router.put(
                                                                `/planned-meals/${slot.planned_meal?.id}/move`,
                                                                {
                                                                    meal_slot_id:
                                                                        Number(
                                                                            event
                                                                                .target
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
                                                    {emptySlots.map(
                                                        (target) => (
                                                            <option
                                                                key={target.id}
                                                                value={
                                                                    target.id
                                                                }
                                                            >
                                                                {formatDay(
                                                                    target.date,
                                                                )}{' '}
                                                                · {target.kind}
                                                            </option>
                                                        ),
                                                    )}
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

                <section>
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                        <UsersRound className="size-4" /> Household truth
                    </h2>
                    <div className="space-y-4">
                        <HouseholdTruthGroup
                            title="Safety rules"
                            empty="No confirmed allergies or safety rules"
                        >
                            {constraints.map((item) => (
                                <EditableConstraint
                                    key={item.id}
                                    constraint={item}
                                    owner={ownerFor(item.person_id)}
                                />
                            ))}
                        </HouseholdTruthGroup>
                        <HouseholdTruthGroup
                            title="Stated preferences"
                            empty="Nothing stated yet"
                        >
                            {preferences
                                .filter(
                                    (item) =>
                                        item.provenance === 'stated' ||
                                        item.provenance === 'feedback',
                                )
                                .map((item) => (
                                    <EditablePreference
                                        key={item.id}
                                        preference={item}
                                        owner={ownerFor(item.person_id)}
                                    />
                                ))}
                        </HouseholdTruthGroup>
                        <HouseholdTruthGroup
                            title="Working defaults"
                            empty="No planning defaults yet"
                        >
                            {preferences
                                .filter((item) => item.provenance === 'default')
                                .map((item) => (
                                    <EditablePreference
                                        key={item.id}
                                        preference={item}
                                        owner={ownerFor(item.person_id)}
                                    />
                                ))}
                        </HouseholdTruthGroup>
                        <HouseholdTruthGroup
                            title="Chef inferences"
                            empty="Chef has not inferred anything yet"
                        >
                            {preferences
                                .filter(
                                    (item) => item.provenance === 'inferred',
                                )
                                .map((item) => (
                                    <EditablePreference
                                        key={item.id}
                                        preference={item}
                                        owner={ownerFor(item.person_id)}
                                    />
                                ))}
                        </HouseholdTruthGroup>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-muted-foreground">
                        Safety rules are explicitly confirmed. Inferences stay
                        labelled and editable.
                    </p>
                </section>

                <section>
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                        <MailPlus className="size-4" /> Plan together
                    </h2>
                    <form
                        className="flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.post(
                                '/team-invitations',
                                { email: inviteEmail },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setInviteEmail(''),
                                },
                            );
                        }}
                    >
                        <Input
                            type="email"
                            value={inviteEmail}
                            onChange={(event) =>
                                setInviteEmail(event.target.value)
                            }
                            placeholder="name@example.com"
                            aria-label="Invite email"
                            required
                        />
                        <Button type="submit" variant="outline">
                            Invite
                        </Button>
                    </form>
                    {flash.invitationUrl && (
                        <div className="mt-3 rounded-lg border bg-background p-3">
                            <p className="text-xs font-medium">
                                Invitation ready
                            </p>
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
            </div>
        </aside>
    );
}

export default function MealPlanShow({ workspace }: { workspace: Workspace }) {
    const { auth } = usePage().props;
    const [messages, setMessages] = useState(workspace.conversation.messages);
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const pendingProposals = workspace.plan.proposals.filter(
        (proposal) => proposal.status === 'pending',
    );
    const slotsById = useMemo(
        () => new Map(workspace.plan.slots.map((slot) => [slot.id, slot])),
        [workspace.plan.slots],
    );

    const sendMessage = async (event: FormEvent) => {
        event.preventDefault();
        const content = input.trim();

        if (!content || sending) {
            return;
        }

        const clientMessageId = crypto.randomUUID();
        const assistantId = `assistant-${clientMessageId}`;
        setInput('');
        setError(null);
        setSending(true);
        setMessages((current) => [
            ...current,
            { id: clientMessageId, role: 'user', content, author: auth.user },
            { id: assistantId, role: 'assistant', content: '' },
        ]);

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(
                `/conversations/${workspace.conversation.id}/messages/stream`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/x-ndjson',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf ?? '',
                    },
                    body: JSON.stringify({
                        content,
                        client_message_id: clientMessageId,
                    }),
                },
            );

            if (!response.ok || !response.body) {
                throw new Error('Unable to start Chef response.');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let finished = false;

            while (!finished) {
                const result = await reader.read();
                finished = result.done;
                buffer += decoder.decode(result.value, { stream: !finished });
                const lines = buffer.split('\n');
                buffer = lines.pop() ?? '';

                for (const line of lines.filter(Boolean)) {
                    const streamed = JSON.parse(line) as StreamEvent;

                    if (streamed.type === 'delta') {
                        setMessages((current) =>
                            current.map((message) =>
                                message.id === assistantId
                                    ? {
                                          ...message,
                                          content:
                                              message.content +
                                              (streamed.delta ?? ''),
                                      }
                                    : message,
                            ),
                        );
                    }

                    if (streamed.type === 'error') {
                        throw new Error(streamed.message);
                    }
                }
            }

            router.reload({
                only: ['workspace'],
                onSuccess: (page) => {
                    const refreshed = page.props.workspace as Workspace;
                    setMessages(refreshed.conversation.messages);
                },
            });
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Chef could not respond.',
            );
            setMessages((current) =>
                current.filter((message) => message.id !== assistantId),
            );
        } finally {
            setSending(false);
        }
    };

    return (
        <>
            <Head title={workspace.plan.title} />
            <div className="flex min-h-0 flex-1 flex-col bg-background lg:flex-row">
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
                        <div
                            className="flex-1 space-y-6 pb-10"
                            aria-live="polite"
                        >
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

                            {pendingProposals.map((proposal) => {
                                const slot = proposal.meal_slot_id
                                    ? slotsById.get(proposal.meal_slot_id)
                                    : null;
                                const replacing = slot?.planned_meal !== null;

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
                                                            {formatDay(
                                                                slot.date,
                                                            )}{' '}
                                                            · {slot.kind}
                                                        </span>
                                                    )}
                                                    {proposal.estimated_minutes && (
                                                        <span className="flex items-center gap-1">
                                                            <Clock3 className="size-3" />{' '}
                                                            {
                                                                proposal.estimated_minutes
                                                            }{' '}
                                                            min
                                                        </span>
                                                    )}
                                                    {proposal.estimated_cost && (
                                                        <span data-numeric="tabular">
                                                            ~$
                                                            {proposal.estimated_cost.toFixed(
                                                                2,
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-4 flex gap-2">
                                                    <Button
                                                        size="sm"
                                                        disabled={
                                                            !proposal.meal_slot_id
                                                        }
                                                        onClick={() =>
                                                            router.put(
                                                                `/meal-proposals/${proposal.id}/accept`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Check />{' '}
                                                        {replacing
                                                            ? 'Replace'
                                                            : 'Accept'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.put(
                                                                `/meal-proposals/${proposal.id}/reject`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
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
                            })}
                        </div>

                        <form
                            onSubmit={sendMessage}
                            className="sticky bottom-4 rounded-2xl border bg-card p-3 shadow-lg"
                        >
                            <textarea
                                value={input}
                                onChange={(event) =>
                                    setInput(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (
                                        event.key === 'Enter' &&
                                        !event.shiftKey
                                    ) {
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
                                    <span className="sr-only">
                                        Send message
                                    </span>
                                </Button>
                            </div>
                        </form>
                    </div>
                </main>
                <PlanInspector workspace={workspace} />
            </div>
        </>
    );
}
