import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import ConversationMessageStreamController from '@/actions/App/Http/Controllers/ConversationMessageStreamController';
import type {
    MealPlanWorkspace,
    Message,
    PendingToolApproval,
    SelectedPhoto,
    StreamEvent,
} from './types';

type ConversationRequest =
    | { content: string }
    | { approval: { id: string; decision: 'approve' | 'reject' } };

const acceptedImageTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
const heicImageTypes = new Set([
    'image/heic',
    'image/heif',
    'image/x-heic',
    'image/x-heif',
]);
const maximumImageBytes = 10 * 1024 * 1024;
const maximumImages = 4;

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
            const payload = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            message =
                Object.values(payload.errors ?? {}).flat()[0] ??
                payload.message ??
                message;
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

function photoSignature(file: File) {
    return `${file.name}:${file.size}:${file.lastModified}:${file.type}`;
}

function isHeicCandidate(file: File) {
    const extension = file.name.split('.').pop()?.toLowerCase();

    return (
        heicImageTypes.has(file.type.toLowerCase()) ||
        extension === 'heic' ||
        extension === 'heif'
    );
}

function optimisticMimeType(file: File) {
    if (file.type !== '') {
        return file.type;
    }

    return file.name.toLowerCase().endsWith('.heif')
        ? 'image/heif'
        : 'image/heic';
}

function withoutSelectedPhoto(photos: SelectedPhoto[], id: number | string) {
    const remaining: SelectedPhoto[] = [];

    for (const photo of photos) {
        if (photo.id !== id) {
            remaining.push({ ...photo, position: remaining.length });
        }
    }

    return remaining;
}

function usesAppleWebKit() {
    const userAgent = navigator.userAgent;

    return (
        /iPhone|iPad|iPod/.test(userAgent) ||
        (userAgent.includes('AppleWebKit') &&
            !/(Chrome|Chromium|Edg|OPR|SamsungBrowser)/.test(userAgent))
    );
}

export function useChefConversation(
    conversation: MealPlanWorkspace['conversation'],
) {
    const { auth } = usePage().props;
    const [optimisticMessages, setOptimisticMessages] = useState<
        Message[] | null
    >(null);
    const [input, setInput] = useState('');
    const [selectedPhotos, setSelectedPhotos] = useState<SelectedPhoto[]>([]);
    const [sendPhase, setSendPhase] = useState<
        'idle' | 'uploading' | 'replying'
    >('idle');
    const [activeClientMessageId, setActiveClientMessageId] = useState<
        string | null
    >(null);
    const [error, setError] = useState<string | null>(null);
    const retainedPreviewUrls = useRef(new Map<string, string[]>());
    const retainedPhotos = useRef(new Map<string, SelectedPhoto[]>());
    const ownedPreviewUrls = useRef(new Set<string>());
    const pendingHeicPreviewIds = useRef(new Set<string>());
    const heicPreviewQueue = useRef<Promise<void> | null>(null);
    const previewGeneration = useRef(0);
    const messages = optimisticMessages ?? conversation.messages;
    const sending = sendPhase !== 'idle';

    useEffect(() => {
        const previews = retainedPreviewUrls.current;
        const photos = retainedPhotos.current;
        const ownedUrls = ownedPreviewUrls.current;
        const pendingIds = pendingHeicPreviewIds.current;
        const generation = ++previewGeneration.current;

        return () => {
            if (previewGeneration.current === generation) {
                previewGeneration.current += 1;
            }

            ownedUrls.forEach((url) => URL.revokeObjectURL(url));
            ownedUrls.clear();
            pendingIds.clear();
            previews.clear();
            photos.clear();
        };
    }, [conversation.id]);

    const createPreviewUrl = (blob: Blob) => {
        const url = URL.createObjectURL(blob);
        ownedPreviewUrls.current.add(url);

        return url;
    };

    const revokePreviewUrl = (url?: string) => {
        if (!url || !ownedPreviewUrls.current.delete(url)) {
            return;
        }

        URL.revokeObjectURL(url);
    };

    const releaseRetainedPreviews = (clientMessageId: string) => {
        retainedPreviewUrls.current
            .get(clientMessageId)
            ?.forEach((url) => revokePreviewUrl(url));
        retainedPreviewUrls.current.delete(clientMessageId);
        retainedPhotos.current.delete(clientMessageId);
    };

    const rejectHeicPreview = (id: string, message: string) => {
        pendingHeicPreviewIds.current.delete(id);
        setSelectedPhotos((current) => withoutSelectedPhoto(current, id));
        setError(message);
    };

    const createNativeHeicPreview = async (file: File) => {
        const previewUrl = createPreviewUrl(file);
        const image = new window.Image();

        try {
            await new Promise<void>((resolve, reject) => {
                const timeout = window.setTimeout(
                    () => reject(new Error('Native HEIC preview timed out.')),
                    3000,
                );

                image.onload = () => {
                    window.clearTimeout(timeout);
                    resolve();
                };
                image.onerror = () => {
                    window.clearTimeout(timeout);
                    reject(new Error('Native HEIC preview is unavailable.'));
                };
                image.src = previewUrl;
            });

            return previewUrl;
        } catch {
            revokePreviewUrl(previewUrl);

            return null;
        } finally {
            image.onload = null;
            image.onerror = null;
        }
    };

    const commitHeicPreview = (
        photo: SelectedPhoto,
        generation: number,
        previewUrl: string,
    ) => {
        if (
            previewGeneration.current !== generation ||
            !pendingHeicPreviewIds.current.delete(String(photo.id))
        ) {
            revokePreviewUrl(previewUrl);

            return;
        }

        setSelectedPhotos((current) =>
            current.map((selectedPhoto) =>
                selectedPhoto.id === photo.id
                    ? {
                          ...selectedPhoto,
                          preview_url: previewUrl,
                          preview_error: undefined,
                          state: 'idle',
                      }
                    : selectedPhoto,
            ),
        );
    };

    const prepareHeicPreview = async (
        photo: SelectedPhoto,
        generation: number,
    ) => {
        try {
            if (usesAppleWebKit()) {
                const nativePreviewUrl = await createNativeHeicPreview(
                    photo.file,
                );

                if (!nativePreviewUrl) {
                    throw new Error('Native HEIC preview is unavailable.');
                }

                commitHeicPreview(photo, generation, nativePreviewUrl);

                return;
            }

            const { heicTo, isHeic } = await import('heic-to/csp');

            if (!(await isHeic(photo.file))) {
                if (
                    previewGeneration.current === generation &&
                    pendingHeicPreviewIds.current.has(String(photo.id))
                ) {
                    rejectHeicPreview(
                        String(photo.id),
                        'That file is not a valid HEIC or HEIF photo.',
                    );
                }

                return;
            }

            const preview = await heicTo({
                blob: photo.file,
                type: 'image/jpeg',
                quality: 0.75,
            });
            const previewUrl = createPreviewUrl(preview);
            commitHeicPreview(photo, generation, previewUrl);
        } catch {
            if (
                previewGeneration.current !== generation ||
                !pendingHeicPreviewIds.current.delete(String(photo.id))
            ) {
                return;
            }

            setSelectedPhotos((current) =>
                current.map((selectedPhoto) =>
                    selectedPhoto.id === photo.id
                        ? {
                              ...selectedPhoto,
                              preview_error:
                                  'Preview unavailable — photo will still upload',
                              state: 'error',
                          }
                        : selectedPhoto,
                ),
            );
        }
    };

    const queueHeicPreview = (photo: SelectedPhoto) => {
        const id = String(photo.id);
        const generation = previewGeneration.current;
        pendingHeicPreviewIds.current.add(id);
        heicPreviewQueue.current = (
            heicPreviewQueue.current ?? Promise.resolve()
        )
            .catch(() => undefined)
            .then(() => prepareHeicPreview(photo, generation));
    };

    const addPhotos = (files: File[]) => {
        setError(null);
        const existingSignatures = new Set(
            selectedPhotos.map((photo) => photoSignature(photo.file)),
        );
        const accepted: SelectedPhoto[] = [];
        let rejection: string | null = null;

        for (const file of files) {
            const heicCandidate = isHeicCandidate(file);

            if (!acceptedImageTypes.has(file.type) && !heicCandidate) {
                rejection = 'Choose JPEG, PNG, WebP, HEIC, or HEIF photos.';
                continue;
            }

            if (file.size > maximumImageBytes) {
                rejection = 'Each photo must be 10 MiB or smaller.';
                continue;
            }

            const signature = photoSignature(file);

            if (existingSignatures.has(signature)) {
                rejection = 'That photo is already attached.';
                continue;
            }

            if (selectedPhotos.length + accepted.length >= maximumImages) {
                rejection = 'You can attach up to four photos.';
                break;
            }

            existingSignatures.add(signature);
            const photo: SelectedPhoto = {
                id: crypto.randomUUID(),
                file,
                mime_type: optimisticMimeType(file),
                size_bytes: file.size,
                width: 0,
                height: 0,
                position: selectedPhotos.length + accepted.length,
                preview_url: heicCandidate ? undefined : createPreviewUrl(file),
                state: heicCandidate ? 'processing' : 'idle',
            };
            accepted.push(photo);

            if (heicCandidate) {
                queueHeicPreview(photo);
            }
        }

        if (accepted.length > 0) {
            setSelectedPhotos((current) => [...current, ...accepted]);
        }

        if (rejection) {
            setError(rejection);
        }
    };

    const removePhoto = (id: number | string) => {
        const photo = selectedPhotos.find((candidate) => candidate.id === id);
        pendingHeicPreviewIds.current.delete(String(id));
        revokePreviewUrl(photo?.preview_url);
        setSelectedPhotos((current) => withoutSelectedPhoto(current, id));
    };

    const requestResponse = async (
        request: ConversationRequest,
        clientMessageId: string,
        assistantId: string,
        baseMessages: Message[],
        photos: SelectedPhoto[] = [],
    ) => {
        setError(null);
        setSendPhase(photos.length > 0 ? 'uploading' : 'replying');
        setActiveClientMessageId(clientMessageId);

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const headers: HeadersInit = {
                Accept: 'application/x-ndjson',
                'X-CSRF-TOKEN': csrf ?? '',
            };
            let body: FormData | string;

            if (photos.length > 0 && 'content' in request) {
                const form = new FormData();
                form.append('content', request.content);
                form.append('client_message_id', clientMessageId);
                photos.forEach((photo) => form.append('images[]', photo.file));
                body = form;
            } else {
                headers['Content-Type'] = 'application/json';
                body = JSON.stringify({
                    ...request,
                    client_message_id: clientMessageId,
                });
            }

            const response = await fetch(
                ConversationMessageStreamController[
                    '/conversations/{conversation}/messages/stream'
                ].url(conversation.id),
                { method: 'POST', headers, body },
            );

            setSendPhase('replying');
            setOptimisticMessages((current) =>
                (current ?? baseMessages).map((message) =>
                    message.client_message_id === clientMessageId
                        ? {
                              ...message,
                              attachments: message.attachments?.map(
                                  (attachment) => ({
                                      ...attachment,
                                      state: 'processing',
                                  }),
                              ),
                          }
                        : message,
                ),
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
                              attachments: message.attachments?.map(
                                  (attachment) => ({
                                      ...attachment,
                                      state: 'done',
                                  }),
                              ),
                          }
                        : message,
                ),
            );
            router.reload({
                only: ['workspace'],
                onSuccess: () => {
                    setOptimisticMessages(null);
                    releaseRetainedPreviews(clientMessageId);
                },
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
                router.reload({
                    only: ['workspace'],
                    onSuccess: () => {
                        setOptimisticMessages(null);
                        releaseRetainedPreviews(clientMessageId);
                    },
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
                                  attachments: item.attachments?.map(
                                      (attachment) => ({
                                          ...attachment,
                                          state: 'error',
                                      }),
                                  ),
                              }
                            : item,
                    );

                    return updated;
                }, []),
            );
        } finally {
            setSendPhase('idle');
            setActiveClientMessageId(null);
        }
    };

    const sendMessage = async (event: FormEvent) => {
        event.preventDefault();
        const content = input.trim();
        const photos = selectedPhotos;
        const preparingPhotos = photos.some(
            (photo) => photo.state === 'processing',
        );

        if (
            (content === '' && photos.length === 0) ||
            sending ||
            preparingPhotos
        ) {
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
                attachments: photos.map((photo) => ({
                    id: photo.id,
                    mime_type: photo.mime_type,
                    size_bytes: photo.size_bytes,
                    width: photo.width,
                    height: photo.height,
                    position: photo.position,
                    preview_url: photo.preview_url,
                    preview_error: photo.preview_error,
                    state: 'uploading',
                })),
                client_message_id: clientMessageId,
                response_status: 'processing',
                response_error: null,
            },
            { id: assistantId, role: 'assistant', content: '' },
        ];

        retainedPreviewUrls.current.set(
            clientMessageId,
            photos
                .map((photo) => photo.preview_url)
                .filter((url): url is string => url !== undefined),
        );
        retainedPhotos.current.set(clientMessageId, photos);
        setInput('');
        setSelectedPhotos([]);
        setOptimisticMessages(nextMessages);
        await requestResponse(
            { content },
            clientMessageId,
            assistantId,
            nextMessages,
            photos,
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
                          attachments: item.attachments?.map((attachment) => ({
                              ...attachment,
                              state: 'processing' as const,
                          })),
                      }
                    : item,
            ),
            { id: assistantId, role: 'assistant', content: '' },
        ];

        setOptimisticMessages(nextMessages);
        await requestResponse(
            message.metadata?.tool_approval
                ? { approval: message.metadata.tool_approval }
                : { content: message.content },
            clientMessageId,
            assistantId,
            nextMessages,
            retainedPhotos.current.get(clientMessageId) ?? [],
        );
    };

    const resolveToolApproval = async (
        approval: PendingToolApproval,
        decision: 'approve' | 'reject',
    ) => {
        if (sending) {
            return;
        }

        const clientMessageId = crypto.randomUUID();
        const assistantId = `assistant-${clientMessageId}`;
        const content =
            decision === 'approve'
                ? 'Approve this meal plan.'
                : 'Keep editing this meal plan.';
        const toolApproval = { id: approval.id, decision };
        const nextMessages: Message[] = [
            ...messages,
            {
                id: clientMessageId,
                role: 'user',
                content,
                author: auth.user,
                metadata: { tool_approval: toolApproval },
                client_message_id: clientMessageId,
                response_status: 'processing',
                response_error: null,
            },
            { id: assistantId, role: 'assistant', content: '' },
        ];

        setOptimisticMessages(nextMessages);
        await requestResponse(
            { approval: toolApproval },
            clientMessageId,
            assistantId,
            nextMessages,
        );
    };

    return {
        activeClientMessageId,
        addPhotos,
        error,
        input,
        messages,
        removePhoto,
        resolveToolApproval,
        retryMessage,
        selectedPhotos,
        sendPhase,
        sending,
        sendMessage,
        setInput,
    };
}
