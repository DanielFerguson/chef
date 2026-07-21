import { router } from '@inertiajs/react';
import { Check, Copy, ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { ConversationFeedback } from './types';

const reasonOptions = [
    ['misunderstood', 'Misunderstood me'],
    ['wrong_action', 'Changed the wrong thing'],
    ['plan_not_updated', "Didn't update the plan"],
    ['incorrect_household_information', 'Incorrect household information'],
    ['poor_recommendation', 'Poor recommendation'],
    ['no_forward_momentum', "Didn't move me forward"],
    ['response_failure', 'Response or action failed'],
] as const;

type Rating = ConversationFeedback['rating'];

function FeedbackDetails({
    action,
    feedback,
    rating,
    extraData = {},
    onSaved,
    onCancel,
}: {
    action: string;
    feedback?: ConversationFeedback;
    rating: Rating;
    extraData?: Record<string, string>;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [reasons, setReasons] = useState<string[]>(feedback?.reasons ?? []);
    const [comment, setComment] = useState(feedback?.comment ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const toggleReason = (reason: string) => {
        setReasons((current) =>
            current.includes(reason)
                ? current.filter((value) => value !== reason)
                : [...current, reason],
        );
    };

    return (
        <form
            className="mt-2 max-w-xl rounded-xl bg-muted/50 p-3"
            onSubmit={(event) => {
                event.preventDefault();
                setSaving(true);
                setError(null);
                router.put(
                    action,
                    {
                        ...extraData,
                        rating,
                        reasons,
                        comment: comment.trim() || null,
                    },
                    {
                        preserveScroll: true,
                        onSuccess: onSaved,
                        onError: (errors) => {
                            const firstError = Object.values(errors)[0];

                            setError(
                                typeof firstError === 'string'
                                    ? firstError
                                    : 'Chef could not save that feedback. Please try again.',
                            );
                        },
                        onFinish: () => setSaving(false),
                    },
                );
            }}
        >
            <p className="text-xs font-medium">
                {rating === 'unhelpful'
                    ? 'What could Chef improve?'
                    : 'What worked well?'}
            </p>
            {rating === 'unhelpful' && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {reasonOptions.map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={reasons.includes(value)}
                            onClick={() => toggleReason(value)}
                            className="rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-foreground"
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}
            <label className="mt-3 block text-xs text-muted-foreground">
                Optional context
                <textarea
                    value={comment}
                    onChange={(event) => setComment(event.target.value)}
                    rows={2}
                    maxLength={2000}
                    className="mt-1 w-full resize-y rounded-lg border bg-background px-3 py-2 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    placeholder="Tell us what happened…"
                />
            </label>
            {error && (
                <p className="mt-2 text-xs text-destructive" role="alert">
                    {error}
                </p>
            )}
            <div className="mt-2 flex items-center gap-2">
                <Button type="submit" size="sm" disabled={saving}>
                    {saving ? 'Saving…' : 'Save feedback'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                    disabled={saving}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export function MessageFeedback({
    content,
    messageId,
    feedback,
}: {
    content: string;
    messageId: number;
    feedback?: ConversationFeedback;
}) {
    const [editing, setEditing] = useState<Rating | null>(null);
    const [saved, setSaved] = useState(false);
    const [copied, setCopied] = useState(false);
    const action = `/messages/${messageId}/feedback`;

    const choose = (rating: Rating) => {
        setSaved(false);

        if (feedback?.rating === rating && editing === null) {
            router.delete(action, { preserveScroll: true });

            return;
        }

        router.put(
            action,
            { rating, reasons: [], comment: null },
            {
                preserveScroll: true,
                onSuccess: () => setEditing(rating),
            },
        );
    };

    return (
        <div className="mt-1">
            <div className="flex items-center gap-0.5 text-muted-foreground">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={
                        copied ? 'Chef response copied' : 'Copy Chef response'
                    }
                    title={copied ? 'Copied' : 'Copy'}
                    onClick={async () => {
                        try {
                            await navigator.clipboard.writeText(content);
                            setCopied(true);
                            window.setTimeout(() => setCopied(false), 2000);
                        } catch {
                            setCopied(false);
                        }
                    }}
                    className="size-7"
                >
                    {copied ? <Check /> : <Copy />}
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Mark this response helpful"
                    aria-pressed={feedback?.rating === 'helpful'}
                    onClick={() => choose('helpful')}
                    className="size-7 aria-pressed:bg-muted aria-pressed:text-foreground"
                >
                    <ThumbsUp />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Mark this response unhelpful"
                    aria-pressed={feedback?.rating === 'unhelpful'}
                    onClick={() => choose('unhelpful')}
                    className="size-7 aria-pressed:bg-muted aria-pressed:text-foreground"
                >
                    <ThumbsDown />
                </Button>
                {feedback && editing === null && (
                    <button
                        type="button"
                        className="px-1 text-xs hover:text-foreground"
                        onClick={() => setEditing(feedback.rating)}
                    >
                        Add context
                    </button>
                )}
                {saved && editing === null && (
                    <span className="px-1 text-xs">Feedback saved</span>
                )}
            </div>
            {editing && (
                <FeedbackDetails
                    action={action}
                    feedback={feedback}
                    rating={editing}
                    onSaved={() => {
                        setEditing(null);
                        setSaved(true);
                    }}
                    onCancel={() => {
                        setEditing(null);
                        setSaved(true);
                    }}
                />
            )}
        </div>
    );
}

export function PlanningCheckpointFeedback({
    conversationId,
    feedback,
}: {
    conversationId: number;
    feedback?: ConversationFeedback;
}) {
    const [editing, setEditing] = useState<Rating | null>(null);
    const [saved, setSaved] = useState(false);
    const action = `/conversations/${conversationId}/feedback`;

    const choose = (rating: Rating) => {
        setSaved(false);
        router.put(
            action,
            {
                context: 'planning_confirmed',
                rating,
                reasons: [],
                comment: null,
            },
            {
                preserveScroll: true,
                onSuccess: () => setEditing(rating),
            },
        );
    };

    return (
        <section className="mx-auto w-full max-w-xl rounded-xl bg-muted/40 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-sm font-medium">
                        How did planning feel?
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Did Chef understand your household and help you reach a
                        useful plan?
                    </p>
                </div>
                <div className="flex gap-1">
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Planning was helpful"
                        aria-pressed={feedback?.rating === 'helpful'}
                        onClick={() => choose('helpful')}
                        className="size-8"
                    >
                        <ThumbsUp />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Planning was unhelpful"
                        aria-pressed={feedback?.rating === 'unhelpful'}
                        onClick={() => choose('unhelpful')}
                        className="size-8"
                    >
                        <ThumbsDown />
                    </Button>
                </div>
            </div>
            {feedback && editing === null && (
                <button
                    type="button"
                    className="mt-2 text-xs text-muted-foreground hover:text-foreground"
                    onClick={() => setEditing(feedback.rating)}
                >
                    Add optional context
                </button>
            )}
            {saved && editing === null && (
                <p className="mt-2 text-xs text-muted-foreground" role="status">
                    Thanks — feedback saved.
                </p>
            )}
            {editing && (
                <FeedbackDetails
                    action={action}
                    feedback={feedback}
                    rating={editing}
                    extraData={{ context: 'planning_confirmed' }}
                    onSaved={() => {
                        setEditing(null);
                        setSaved(true);
                    }}
                    onCancel={() => {
                        setEditing(null);
                        setSaved(true);
                    }}
                />
            )}
        </section>
    );
}
