import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ConversationMessageStreamController from '@/actions/App/Http/Controllers/ConversationMessageStreamController';
import type { MealPlanWorkspace, Message, StreamEvent } from './types';

async function consumeStream(
    response: Response,
    onDelta: (delta: string) => void,
) {
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
            const event = JSON.parse(line) as StreamEvent;

            if (event.type === 'delta') {
                onDelta(event.delta ?? '');
            }

            if (event.type === 'error') {
                throw new Error(event.message ?? 'Chef could not respond.');
            }
        }
    }
}

export function useChefConversation(workspace: MealPlanWorkspace) {
    const { auth } = usePage().props;
    const [optimisticMessages, setOptimisticMessages] = useState<
        Message[] | null
    >(null);
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const messages = optimisticMessages ?? workspace.conversation.messages;

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
        setOptimisticMessages([
            ...messages,
            {
                id: clientMessageId,
                role: 'user',
                content,
                author: auth.user,
            },
            { id: assistantId, role: 'assistant', content: '' },
        ]);

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(
                ConversationMessageStreamController.url(
                    workspace.conversation.id,
                ),
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
            await consumeStream(response, (delta) =>
                setOptimisticMessages((current) =>
                    (current ?? messages).map((message) =>
                        message.id === assistantId
                            ? {
                                  ...message,
                                  content: message.content + delta,
                              }
                            : message,
                    ),
                ),
            );
            setSending(false);
            router.reload({
                only: ['workspace'],
                onSuccess: () => setOptimisticMessages(null),
            });
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Chef could not respond.',
            );
            setOptimisticMessages((current) =>
                (current ?? messages).filter(
                    (message) => message.id !== assistantId,
                ),
            );
            setSending(false);
        }
    };

    return { error, input, messages, sending, sendMessage, setInput };
}
