import { ArrowRight, ArrowUp } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';

export function ConversationComposer({
    error,
    input,
    onSubmit,
    sending,
    setInput,
}: {
    error: string | null;
    input: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    sending: boolean;
    setInput: (value: string) => void;
}) {
    return (
        <form
            onSubmit={onSubmit}
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
                <p role="alert" className="px-2 pb-2 text-xs text-destructive">
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
    );
}
