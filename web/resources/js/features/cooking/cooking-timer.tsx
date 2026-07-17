import { BellRing, Pause, Play, RotateCcw, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';

type TimerState = {
    endsAt: number;
    pausedSeconds: number | null;
};

function readTimers(storageKey: string): Record<string, TimerState> {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        return JSON.parse(window.sessionStorage.getItem(storageKey) ?? '{}');
    } catch {
        return {};
    }
}

function formatSeconds(seconds: number) {
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;

    return `${minutes}:${remainder.toString().padStart(2, '0')}`;
}

export function CookingTimer({
    mealId,
    stepId,
    minutes,
}: {
    mealId: number;
    stepId: number;
    minutes: number;
}) {
    const storageKey = `chef:cooking-timers:${mealId}`;
    const timerKey = stepId.toString();
    const [timers, setTimers] = useState<Record<string, TimerState>>(() =>
        readTimers(storageKey),
    );
    const [now, setNow] = useState(() => Date.now());
    const timer = timers[timerKey];
    const seconds = timer
        ? (timer.pausedSeconds ??
          Math.max(0, Math.ceil((timer.endsAt - now) / 1000)))
        : minutes * 60;
    const finished = timer !== undefined && seconds === 0;

    useEffect(() => {
        if (!timer || timer.pausedSeconds !== null || finished) {
            return;
        }

        const interval = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(interval);
    }, [finished, timer]);

    const save = (next: Record<string, TimerState>) => {
        setTimers(next);
        window.sessionStorage.setItem(storageKey, JSON.stringify(next));
    };

    const start = (duration = minutes * 60) => {
        save({
            ...timers,
            [timerKey]: {
                endsAt: Date.now() + duration * 1000,
                pausedSeconds: null,
            },
        });
        setNow(Date.now());
    };

    return (
        <div
            className="mt-5 flex flex-wrap items-center gap-3 rounded-xl border bg-background p-3"
            aria-live="polite"
        >
            {finished ? (
                <BellRing className="size-5 text-primary" />
            ) : (
                <Timer className="size-5 text-muted-foreground" />
            )}
            <span className="min-w-20 font-mono text-xl font-semibold tabular-nums">
                {formatSeconds(seconds)}
            </span>
            <span className="text-sm text-muted-foreground">
                {finished
                    ? 'Timer finished'
                    : timer
                      ? timer.pausedSeconds === null
                          ? 'Running'
                          : 'Paused'
                      : `${minutes} minute timer`}
            </span>
            <div className="ml-auto flex gap-2">
                {!timer || finished ? (
                    <Button size="sm" onClick={() => start()}>
                        <Play /> {finished ? 'Restart' : 'Start'}
                    </Button>
                ) : timer.pausedSeconds === null ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            save({
                                ...timers,
                                [timerKey]: {
                                    ...timer,
                                    pausedSeconds: seconds,
                                },
                            })
                        }
                    >
                        <Pause /> Pause
                    </Button>
                ) : (
                    <Button size="sm" onClick={() => start(seconds)}>
                        <Play /> Resume
                    </Button>
                )}
                {timer && (
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={() => {
                            const next = { ...timers };
                            delete next[timerKey];
                            save(next);
                        }}
                    >
                        <RotateCcw />
                        <span className="sr-only">Reset timer</span>
                    </Button>
                )}
            </div>
        </div>
    );
}
