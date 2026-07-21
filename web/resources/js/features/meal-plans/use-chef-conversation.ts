import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ConversationMessageStreamController from '@/actions/App/Http/Controllers/ConversationMessageStreamController';
import type { MealPlanWorkspace, Message, StreamEvent } from './types';

class ConversationResponseError extends Error {
    constructor(
        message: string,
        readonly status: number | null = null,
        readonly code: StreamEvent['code'] = undefined,
        readonly retryable = true,
    ) {
        super(message);
        this.name = 'ConversationResponseError';
    }
}

async function consumeStream(
    response: Response,
    onDelta: (delta: string) => void,
) {
    if (!response.ok) {
        let message = 'Unable to start Chef response.';

        try {
            const payload = (await response.json()) as { message?: string };
            message = payload.message ?? message;
        } catch {
            // Keep the safe fallback when the response is not JSON.
        }

        throw new ConversationResponseError(message, response.status);
    }

    if (!response.body) {
        throw new ConversationResponseError(
            'Chef opened a response without a readable stream.',
        );
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
                throw new ConversationResponseError(
                    event.message ?? 'Chef could not respond.',
                    null,
                    event.code,
                    event.retryable ?? true,
                );
            }
        }
    }
}

export function useChefConversation(
    conversation: MealPlanWorkspace['conversation'],
) {
    const { auth } = usePage().props;
    const [optimisticMessages, setOptimisticMessages] = useState<
        Message[] | null
    >(null);
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [activeClientMessageId, setActiveClientMessageId] = useState<
        string | null
    >(null);
    const [error, setError] = useState<string | null>(null);
    const messages = optimisticMessages ?? conversation.messages;

    const requestResponse = async (
        content: string,
        clientMessageId: string,
        assistantId: string,
        baseMessages: Message[],
    ) => {
        setError(null);
        setSending(true);
        setActiveClientMessageId(clientMessageId);

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(
                ConversationMessageStreamController.url(conversation.id),
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
                    (current ?? baseMessages).map((message) =>
                        message.id === assistantId
                            ? {
                                  ...message,
                                  content: message.content + delta,
                              }
                            : message,
                    ),
                ),
            );
            setOptimisticMessages((current) =>
                (current ?? baseMessages).map((message) =>
                    message.client_message_id === clientMessageId
                        ? {
                              ...message,
                              response_status: 'completed',
                              response_error: null,
                          }
                        : message,
                ),
            );
            setSending(false);
            setActiveClientMessageId(null);
            router.reload({
                only: ['workspace'],
                onSuccess: () => setOptimisticMessages(null),
            });
        } catch (exception) {
            const message =
                exception instanceof Error
                    ? exception.message
                    : 'Chef could not respond.';

            if (
                exception instanceof ConversationResponseError &&
                exception.status === 409
            ) {
                setError(message);
                setSending(false);
                setActiveClientMessageId(null);
                router.reload({
                    only: ['workspace'],
                    onSuccess: () => setOptimisticMessages(null),
                });

                return;
            }

            setError(message);
            setOptimisticMessages((current) =>
                (current ?? baseMessages).reduce<Message[]>((updated, item) => {
                    if (item.id === assistantId) {
                        return updated;
                    }

                    updated.push(
                        item.client_message_id === clientMessageId
                            ? {
                                  ...item,
                                  response_status: 'failed',
                                  response_error: message,
                              }
                            : item,
                    );

                    return updated;
                }, []),
            );
            setSending(false);
            setActiveClientMessageId(null);
        }
    };

    const sendMessage = async (event: FormEvent) => {
        event.preventDefault();
        const content = input.trim();

        if (!content || sending) {
            return;
        }

        const clientMessageId = crypto.randomUUID();
        const assistantId = `assistant-${clientMessageId}`;
        const nextMessages: Message[] = [
            ...messages,
            {
                id: clientMessageId,
                role: 'user',
                content,
                author: auth.user,
                client_message_id: clientMessageId,
                response_status: 'processing',
                response_error: null,
            },
            { id: assistantId, role: 'assistant', content: '' },
        ];

        setInput('');
        setOptimisticMessages(nextMessages);
        await requestResponse(
            content,
            clientMessageId,
            assistantId,
            nextMessages,
        );
    };

    const retryMessage = async (message: Message) => {
        if (
            sending ||
            message.role !== 'user' ||
            message.response_status !== 'failed' ||
            !message.client_message_id
        ) {
            return;
        }

        const clientMessageId = message.client_message_id;
        const assistantId = `assistant-${clientMessageId}-retry`;
        const nextMessages: Message[] = [
            ...messages.map((item) =>
                item.id === message.id
                    ? {
                          ...item,
                          response_status: 'processing' as const,
                          response_error: null,
                      }
                    : item,
            ),
            { id: assistantId, role: 'assistant', content: '' },
        ];

        setOptimisticMessages(nextMessages);
        await requestResponse(
            message.content,
            clientMessageId,
            assistantId,
            nextMessages,
        );
    };

    return {
        activeClientMessageId,
        error,
        input,
        messages,
        retryMessage,
        sending,
        sendMessage,
        setInput,
    };
}
