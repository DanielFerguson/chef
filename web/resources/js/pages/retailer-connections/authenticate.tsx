import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, LockKeyhole, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';

type AuthenticationPageProps = {
    connection: {
        id: number;
        retailer: string;
        status: string;
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

export default function RetailerConnectionAuthenticate({
    connection,
    session,
    return_url: returnUrl,
}: AuthenticationPageProps) {
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
                    throw new Error('The secure sign-in view is unavailable.');
                }

                return (await response.json()) as { live_view_url?: string };
            })
            .then((payload) => {
                if (!payload.live_view_url) {
                    throw new Error('The secure sign-in view is unavailable.');
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
                        : 'The secure sign-in view is unavailable.',
                );
            });

        return () => controller.abort();
    }, [session.live_view_endpoint]);

    return (
        <>
            <Head title="Connect Woolworths" />
            <main className="min-h-0 flex-1 overflow-y-auto bg-background">
                <div className="mx-auto w-full max-w-5xl px-5 py-8 sm:px-8 lg:py-10">
                    <Button asChild variant="ghost" size="sm" className="-ml-2">
                        <Link href={returnUrl}>
                            <ArrowLeft /> Back to shopping
                        </Link>
                    </Button>

                    <header className="mt-5 border-b pb-6">
                        <div className="flex items-center gap-2">
                            <LockKeyhole className="size-5 text-primary" />
                            <h1 className="text-xl font-semibold">
                                Sign in to {connection.retailer}
                            </h1>
                        </div>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Enter your Woolworths password and MFA directly in
                            the secure browser below. The Chef model is not
                            attached during sign-in, and this session is not
                            recorded.
                        </p>
                    </header>

                    <section className="mt-6" aria-label="Secure sign-in">
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
                                    title="Woolworths secure sign-in"
                                    src={liveViewUrl}
                                    sandbox="allow-same-origin allow-scripts allow-forms"
                                    allow="clipboard-read; clipboard-write"
                                    referrerPolicy="no-referrer"
                                    className="h-[68vh] min-h-[32rem] w-full bg-white"
                                />
                            ) : (
                                <div className="flex h-[68vh] min-h-[32rem] items-center justify-center p-6 text-center text-sm text-muted-foreground">
                                    {liveViewError ??
                                        'Opening the secure Woolworths browser…'}
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="mt-5 flex flex-col items-start justify-between gap-3 rounded-xl bg-muted/40 p-4 sm:flex-row sm:items-center">
                        <div>
                            <p className="text-sm font-medium">
                                Finished signing in?
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Chef will run a deterministic protected-cart
                                check, close this browser, and return you to the
                                shopping list. It will not start filling the
                                cart yet.
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
                                    `/browser-sessions/${session.id}/verify`,
                                )
                            }
                        >
                            <Check /> I&rsquo;ve signed in
                        </Button>
                    </section>
                </div>
            </main>
        </>
    );
}
