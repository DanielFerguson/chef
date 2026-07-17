import { useCallback, useEffect, useRef, useState } from 'react';

export type VoiceStatus =
    | 'idle'
    | 'requesting_permission'
    | 'connecting'
    | 'listening'
    | 'thinking'
    | 'speaking'
    | 'reconnecting'
    | 'error';

type RealtimeFunctionCall = {
    type: 'function_call';
    name: string;
    call_id: string;
    arguments: string;
};

type RealtimeServerEvent = {
    type: string;
    transcript?: string;
    delta?: string;
    error?: { code?: string; message?: string };
    response?: {
        status?: string;
        output?: RealtimeFunctionCall[];
    };
};

type VoiceToolResult = {
    assistant_response: string;
    user_message_id: number;
    assistant_message_id: number;
    artifacts: Record<string, unknown>;
};

type VoiceClientCallbacks = {
    onStatus: (status: VoiceStatus) => void;
    onCaption: (caption: string | null) => void;
    onConversationChanged: () => void;
    onError: (message: string) => void;
};

interface VoiceClient {
    start(): Promise<void>;
    end(): Promise<void>;
    setMuted(muted: boolean): void;
    interrupt(): void;
}

function csrfToken() {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function responseMessage(response: Response, fallback: string) {
    try {
        const body = (await response.json()) as { message?: string };

        return body.message ?? fallback;
    } catch {
        return fallback;
    }
}

class BrowserRealtimeVoiceClient implements VoiceClient {
    private peer: RTCPeerConnection | null = null;
    private events: RTCDataChannel | null = null;
    private media: MediaStream | null = null;
    private audio: HTMLAudioElement | null = null;
    private sessionId: string | null = null;
    private transcript: string | null = null;
    private closing = false;
    private reconnecting = false;

    public constructor(
        private readonly conversationId: number,
        private readonly callbacks: VoiceClientCallbacks,
    ) {}

    public async start() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error(
                'This browser cannot share a microphone here. You can keep typing to Chef.',
            );
        }

        this.callbacks.onStatus('requesting_permission');
        this.media = await navigator.mediaDevices.getUserMedia({ audio: true });
        this.callbacks.onStatus('connecting');
        await this.connect();
    }

    public async end() {
        this.closing = true;
        await this.revokeSession();
        this.closePeer();
        this.media?.getTracks().forEach((track) => track.stop());
        this.media = null;
        this.audio?.remove();
        this.audio = null;
        this.callbacks.onCaption(null);
        this.callbacks.onStatus('idle');
    }

    public setMuted(muted: boolean) {
        this.media?.getAudioTracks().forEach((track) => {
            track.enabled = !muted;
        });
    }

    public interrupt() {
        this.send({ type: 'response.cancel' });
        this.send({ type: 'output_audio_buffer.clear' });
        this.callbacks.onStatus('listening');
    }

    private async connect() {
        if (!this.media) {
            throw new Error(
                'Microphone access ended. Start voice again to continue.',
            );
        }

        this.closePeer();
        const peer = new RTCPeerConnection();
        const events = peer.createDataChannel('oai-events');
        this.peer = peer;
        this.events = events;
        this.closing = false;

        if (!this.audio) {
            this.audio = document.createElement('audio');
            this.audio.autoplay = true;
            this.audio.hidden = true;
            this.audio.setAttribute('aria-hidden', 'true');
            document.body.append(this.audio);
        }

        peer.ontrack = (event) => {
            if (!this.audio) {
                return;
            }

            this.audio.srcObject = event.streams[0];
            void this.audio.play().catch(() => undefined);
        };
        peer.onconnectionstatechange = () => {
            if (
                !this.closing &&
                (peer.connectionState === 'failed' ||
                    peer.connectionState === 'disconnected')
            ) {
                void this.reconnect();
            }
        };
        events.addEventListener('open', () =>
            this.callbacks.onStatus('listening'),
        );
        events.addEventListener('message', (event) => {
            void Promise.resolve()
                .then(() =>
                    this.handleEvent(
                        JSON.parse(event.data) as RealtimeServerEvent,
                    ),
                )
                .catch((exception: unknown) =>
                    this.callbacks.onError(voiceError(exception)),
                );
        });
        this.media.getAudioTracks().forEach((track) => {
            peer.addTrack(track, this.media as MediaStream);
        });

        const offer = await peer.createOffer();
        await peer.setLocalDescription(offer);
        const response = await fetch(
            `/conversations/${this.conversationId}/realtime-sessions`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ sdp: offer.sdp }),
                signal: AbortSignal.timeout(35_000),
            },
        );

        if (!response.ok) {
            throw new Error(
                await responseMessage(
                    response,
                    'Voice could not connect. You can keep typing to Chef.',
                ),
            );
        }

        const session = (await response.json()) as {
            voice_session_id: string;
            sdp: string;
        };
        this.sessionId = session.voice_session_id;
        await peer.setRemoteDescription({ type: 'answer', sdp: session.sdp });
    }

    private async handleEvent(event: RealtimeServerEvent) {
        if (
            event.type ===
            'conversation.item.input_audio_transcription.completed'
        ) {
            this.transcript = event.transcript?.trim() || null;
            this.callbacks.onCaption(this.transcript);
        }

        if (
            event.type === 'conversation.item.input_audio_transcription.delta'
        ) {
            this.transcript = `${this.transcript ?? ''}${event.delta ?? ''}`;
            this.callbacks.onCaption(this.transcript.trim() || null);
        }

        if (event.type === 'input_audio_buffer.speech_started') {
            this.transcript = null;
            this.callbacks.onStatus('listening');
        }

        if (event.type === 'input_audio_buffer.speech_stopped') {
            this.callbacks.onStatus('thinking');
        }

        if (event.type === 'response.output_audio.delta') {
            this.callbacks.onStatus('speaking');
        }

        if (event.type === 'response.done') {
            const calls = (event.response?.output ?? []).filter(
                (item) => item.type === 'function_call',
            );

            if (calls.length > 0) {
                for (const call of calls) {
                    await this.handleFunctionCall(call);
                }

                return;
            }

            this.callbacks.onStatus('listening');
        }

        if (event.type === 'error') {
            if (event.error?.code === 'response_cancel_not_active') {
                return;
            }

            this.callbacks.onError(
                event.error?.message ??
                    'Voice hit a problem. You can keep typing to Chef.',
            );
        }
    }

    private async handleFunctionCall(call: RealtimeFunctionCall) {
        if (!this.sessionId) {
            throw new Error('The voice session has ended.');
        }

        this.callbacks.onStatus('thinking');
        let args: { message?: string };

        try {
            args = JSON.parse(call.arguments) as { message?: string };
        } catch {
            throw new Error('Chef could not understand that voice turn.');
        }

        const message = this.transcript?.trim() || args.message?.trim();

        if (!message) {
            throw new Error('Chef could not understand that voice turn.');
        }

        const response = await fetch(
            `/realtime-sessions/${this.sessionId}/tool-calls`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    provider_call_id: call.call_id,
                    tool_name: call.name,
                    arguments: { message },
                }),
                signal: AbortSignal.timeout(120_000),
            },
        );

        if (!response.ok) {
            throw new Error(
                await responseMessage(
                    response,
                    'Chef could not finish that voice turn. You can keep typing.',
                ),
            );
        }

        const result = (await response.json()) as VoiceToolResult;
        this.transcript = null;
        this.callbacks.onCaption(result.assistant_response);
        this.callbacks.onConversationChanged();
        this.send({
            type: 'conversation.item.create',
            item: {
                type: 'function_call_output',
                call_id: call.call_id,
                output: JSON.stringify(result),
            },
        });
        this.send({
            type: 'response.create',
            response: {
                output_modalities: ['audio'],
                tool_choice: 'none',
                instructions:
                    'Speak only the assistant_response from the latest function output. Do not add, omit, or change household facts.',
            },
        });
    }

    private send(event: Record<string, unknown>) {
        if (this.events?.readyState === 'open') {
            this.events.send(JSON.stringify(event));
        }
    }

    private async reconnect() {
        if (this.reconnecting || this.closing) {
            return;
        }

        this.reconnecting = true;
        this.callbacks.onStatus('reconnecting');
        await this.revokeSession();

        for (const delay of [0, 500, 1500]) {
            await new Promise((resolve) => window.setTimeout(resolve, delay));

            try {
                await this.connect();
                this.reconnecting = false;

                return;
            } catch {
                // Try a fresh scoped session while the local microphone remains granted.
            }
        }

        this.reconnecting = false;
        this.callbacks.onError(
            'Voice could not reconnect. Microphone sharing has stopped; you can keep typing.',
        );
        await this.end();
    }

    private closePeer() {
        this.events?.close();
        this.peer?.close();
        this.events = null;
        this.peer = null;
    }

    private async revokeSession() {
        const sessionId = this.sessionId;
        this.sessionId = null;

        if (!sessionId) {
            return;
        }

        await fetch(`/realtime-sessions/${sessionId}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            keepalive: true,
            signal: AbortSignal.timeout(3_000),
        }).catch(() => undefined);
    }
}

class TestRealtimeVoiceClient implements VoiceClient {
    public constructor(private readonly callbacks: VoiceClientCallbacks) {
        document.addEventListener('chef:voice-test', this.handleTestEvent);
    }

    public async start() {
        this.callbacks.onStatus('requesting_permission');
        this.callbacks.onStatus('connecting');
        this.callbacks.onStatus('listening');
    }

    public async end() {
        this.callbacks.onCaption(null);
        this.callbacks.onStatus('idle');
        document.removeEventListener('chef:voice-test', this.handleTestEvent);
    }

    public setMuted() {}

    public interrupt() {
        this.callbacks.onStatus('listening');
    }

    private readonly handleTestEvent = (event: Event) => {
        const status = (event as CustomEvent<string>).detail;

        if (status === 'disconnected') {
            this.callbacks.onStatus('reconnecting');
            window.setTimeout(() => this.callbacks.onStatus('listening'), 300);

            return;
        }

        if (status === 'error') {
            this.callbacks.onError(
                'Voice could not reconnect. You can keep typing to Chef.',
            );

            return;
        }

        if (['listening', 'thinking', 'speaking'].includes(status)) {
            this.callbacks.onStatus(status as VoiceStatus);
        }
    };
}

function voiceError(exception: unknown) {
    if (
        exception instanceof DOMException &&
        exception.name === 'NotAllowedError'
    ) {
        return 'Microphone access was not allowed. You can keep typing, or allow it in your browser and try again.';
    }

    if (
        exception instanceof DOMException &&
        exception.name === 'NotSupportedError'
    ) {
        return 'This browser cannot share a microphone here. You can keep typing to Chef.';
    }

    return exception instanceof Error
        ? exception.message
        : 'Voice could not start. You can keep typing to Chef.';
}

export function useRealtimeVoice(
    conversationId: number,
    onConversationChanged: () => void,
    testMode = false,
) {
    const client = useRef<VoiceClient | null>(null);
    const [status, setStatus] = useState<VoiceStatus>(
        testMode ? 'listening' : 'idle',
    );
    const [muted, setMuted] = useState(false);
    const [caption, setCaption] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const stop = useCallback(async () => {
        await client.current?.end();
        client.current = null;
        setMuted(false);
        setCaption(null);
        setStatus('idle');
    }, []);

    const start = useCallback(async () => {
        setError(null);
        const callbacks: VoiceClientCallbacks = {
            onStatus: setStatus,
            onCaption: setCaption,
            onConversationChanged,
            onError: (message) => {
                setError(message);
                setStatus('error');
                void client.current?.end();
            },
        };
        const nextClient = testMode
            ? new TestRealtimeVoiceClient(callbacks)
            : new BrowserRealtimeVoiceClient(conversationId, callbacks);
        client.current = nextClient;

        if (testMode) {
            setStatus('listening');

            return;
        }

        try {
            await nextClient.start();
        } catch (exception) {
            setError(voiceError(exception));
            setStatus('error');
            await nextClient.end();
            client.current = null;
        }
    }, [conversationId, onConversationChanged, testMode]);

    const toggleMute = useCallback(() => {
        const next = !muted;
        client.current?.setMuted(next);
        setMuted(next);
    }, [muted]);

    const interrupt = useCallback(() => client.current?.interrupt(), []);

    useEffect(() => {
        return () => {
            const activeClient = client.current;
            client.current = null;
            void activeClient?.end();
        };
    }, []);

    return {
        caption,
        error,
        interrupt,
        muted,
        start,
        status,
        stop,
        toggleMute,
    };
}
