import { Mic, MicOff, Square, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useRealtimeVoice } from './use-realtime-voice';
import type { VoiceStatus } from './use-realtime-voice';

const statusLabels: Record<VoiceStatus, string> = {
    idle: 'Voice off',
    requesting_permission: 'Waiting for microphone permission…',
    connecting: 'Connecting voice…',
    listening: 'Listening…',
    thinking: 'Chef is thinking…',
    speaking: 'Chef is speaking…',
    reconnecting: 'Reconnecting voice…',
    error: 'Voice unavailable',
};

export function VoiceControls({
    conversationId,
    onConversationChanged,
    testMode,
}: {
    conversationId: number;
    onConversationChanged: () => void;
    testMode: boolean;
}) {
    const [showPermission, setShowPermission] = useState(false);
    const startRequested = useRef(false);
    const {
        caption,
        error,
        interrupt,
        muted,
        start,
        status,
        stop,
        toggleMute,
    } = useRealtimeVoice(conversationId, onConversationChanged, testMode);
    const active = !['idle', 'error'].includes(status);

    if (showPermission) {
        return (
            <section
                aria-label="Microphone permission"
                className="mb-2 rounded-xl bg-muted/60 p-3"
            >
                <p className="text-sm font-medium">Talk with Chef</p>
                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    Your browser will ask for microphone access. Spoken turns
                    and Chef&apos;s replies are saved in this conversation. Stop
                    voice at any time to turn microphone sharing off; typing
                    always remains available.
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        aria-label="Allow microphone for voice conversation"
                        aria-disabled={status !== 'idle'}
                        onClick={() => {
                            if (status !== 'idle' || startRequested.current) {
                                return;
                            }

                            startRequested.current = true;
                            void start().finally(() => {
                                startRequested.current = false;
                                setShowPermission(false);
                            });
                        }}
                    >
                        <Mic /> Allow microphone
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        aria-label="Dismiss microphone permission"
                        onClick={() => setShowPermission(false)}
                    >
                        Not now
                    </Button>
                </div>
            </section>
        );
    }

    if (!active) {
        return (
            <div className="flex min-w-0 items-center gap-2">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Start voice conversation"
                    onClick={() => setShowPermission(true)}
                >
                    <Mic />
                </Button>
                {error && (
                    <p
                        role="alert"
                        className="max-w-sm text-xs text-destructive"
                    >
                        {error}
                    </p>
                )}
            </div>
        );
    }

    return (
        <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
                <span
                    role="status"
                    aria-live="polite"
                    className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        className={cn(
                            'size-2 shrink-0 rounded-full bg-primary',
                            status === 'speaking' && 'animate-pulse',
                        )}
                    />
                    {muted ? 'Microphone muted' : statusLabels[status]}
                </span>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={muted ? 'Unmute microphone' : 'Mute microphone'}
                    onClick={toggleMute}
                >
                    {muted ? <MicOff /> : <Mic />}
                </Button>
                {status === 'speaking' && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={interrupt}
                    >
                        <Square /> Stop Chef
                    </Button>
                )}
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="End voice conversation"
                    onClick={() => void stop()}
                >
                    <X />
                </Button>
            </div>
            {caption && (
                <p
                    aria-live="polite"
                    className="mt-1 line-clamp-2 max-w-xl text-xs leading-5 text-muted-foreground"
                >
                    {caption}
                </p>
            )}
        </div>
    );
}
