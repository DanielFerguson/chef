import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Hand, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';

type TakeoverPageProps = {
    run: {
        id: number;
        shopping_list_revision: number | null;
    };
    session: {
        id: number;
        live_view_endpoint: string;
        expires_at: string | null;
        timezone: string;
        recording_enabled: boolean;
    };
    return_url: string;
};

export default function RetailerConnectionTakeover({
    run,
    session,
    return_url: returnUrl,
}: TakeoverPageProps) {
    const form = useForm({});
    const [liveViewUrl, setLiveViewUrl] = useState<string | null>(null);
    const [liveViewError, setLiveViewError] = useState<string | null>(null);
    const error = Object.values(form.errors).find(
        (value): value is string => typeof value === 'string',
    );

    useEffect(() => {
        const controller = new AbortController();

        void fetch(session.live_view_endpoint, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('The manual browser view is unavailable.');
                }

                return (await response.json()) as { live_view_url?: string };
            })
            .then((payload) => {
                if (!payload.live_view_url) {
                    throw new Error('The manual browser view is unavailable.');
                }

                setLiveViewUrl(payload.live_view_url);
            })
            .catch((fetchError: unknown) => {
                if (
                    fetchError instanceof DOMException &&
                    fetchError.name === 'AbortError'
                ) {
                    return;
                }

                setLiveViewError(
                    fetchError instanceof Error
                        ? fetchError.message
                        : 'The manual browser view is unavailable.',
                );
            });

        return () => controller.abort();
    }, [session.live_view_endpoint]);

    return (
        <>
            <Head title="Take over Woolworths cart" />
            <main className="min-h-0 flex-1 overflow-y-auto bg-background">
                <div className="mx-auto w-full max-w-5xl px-5 py-8 sm:px-8 lg:py-10">
                    <Button asChild variant="ghost" size="sm" className="-ml-2">
                        <Link href={returnUrl}>
                            <ArrowLeft /> Back to shopping
                        </Link>
                    </Button>

                    <header className="mt-5 border-b pb-6">
                        <div className="flex items-center gap-2">
                            <Hand className="size-5 text-primary" />
                            <h1 className="text-xl font-semibold">
                                You control the Woolworths cart
                            </h1>
                        </div>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Chef is paused at shopping-list revision{' '}
                            {run.shopping_list_revision ?? 'unknown'}. Its model
                            is disconnected while you inspect or edit the cart,
                            and this browser session is not recorded.
                        </p>
                    </header>

                    <section
                        className="mt-6"
                        aria-label="Manual Woolworths cart control"
                    >
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1.5">
                                <ShieldCheck className="size-3.5" /> Recording{' '}
                                {session.recording_enabled
                                    ? 'unexpectedly enabled'
                                    : 'disabled'}
                            </span>
                            {session.expires_at && (
                                <span>
                                    Session expires{' '}
                                    {new Date(
                                        session.expires_at,
                                    ).toLocaleTimeString('en-AU', {
                                        timeZone: session.timezone,
                                    })}
                                </span>
                            )}
                        </div>
                        <div className="overflow-hidden rounded-xl border bg-muted/30 shadow-sm">
                            {liveViewUrl ? (
                                <iframe
                                    title="Manual Woolworths cart control"
                                    src={liveViewUrl}
                                    sandbox="allow-same-origin allow-scripts allow-forms"
                                    referrerPolicy="no-referrer"
                                    className={`h-[68vh] min-h-[32rem] w-full bg-white ${form.processing ? 'pointer-events-none opacity-70' : ''}`}
                                />
                            ) : (
                                <div className="flex h-[68vh] min-h-[32rem] items-center justify-center p-6 text-center text-sm text-muted-foreground">
                                    {liveViewError ??
                                        'Opening the Woolworths cart…'}
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="mt-5 flex flex-col items-start justify-between gap-3 rounded-xl bg-muted/40 p-4 sm:flex-row sm:items-center">
                        <div>
                            <p className="text-sm font-medium">
                                Finished with manual control?
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Chef will close this view, inspect the actual
                                cart, and resume only work that is still
                                missing.
                            </p>
                            {error && (
                                <p
                                    className="mt-2 text-xs text-destructive"
                                    role="alert"
                                >
                                    {error}
                                </p>
                            )}
                        </div>
                        <Button
                            disabled={form.processing}
                            onClick={() =>
                                form.post(
                                    `/browser-sessions/${session.id}/takeover/finish`,
                                )
                            }
                        >
                            <Check /> Reconcile and resume
                        </Button>
                    </section>
                </div>
            </main>
        </>
    );
}
