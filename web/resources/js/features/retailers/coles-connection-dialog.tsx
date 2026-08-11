import { router, useHttp } from '@inertiajs/react';
import {
    CheckCircle2,
    ExternalLink,
    LoaderCircle,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GroceryPreparation } from '@/features/meal-plans/types';
import { store, verify } from '@/routes/retailer-connections';
import { destroy as releaseLiveSession } from '@/routes/retailer-connections/live-session';

type ConnectionResponse = {
    connection: {
        id: number;
        status: string;
        requires_standing_consent: boolean;
    };
    session: {
        live_view_url: string;
        expires_at: string;
    };
    consent: NonNullable<GroceryPreparation['consent']>;
};

type VerificationResponse = {
    connection: {
        id: number;
        status: 'connected';
        standing_consent: true;
        last_verified_at: string;
    };
};

export function ColesConnectionDialog({
    groceryPreparation,
    open,
    onOpenChange,
}: {
    groceryPreparation: GroceryPreparation;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const connectionRequest = useHttp<
        Record<string, never>,
        ConnectionResponse
    >({});
    const verificationRequest = useHttp<
        { standing_consent: boolean; disclosure_version: string },
        VerificationResponse
    >({
        standing_consent: false,
        disclosure_version: groceryPreparation.consent?.version ?? '',
    });
    const releaseRequest = useHttp<Record<string, never>, { released: true }>(
        {},
    );
    const [session, setSession] = useState<ConnectionResponse | null>(null);
    const startedForCurrentOpening = useRef(false);
    const openRef = useRef(open);
    const startConnection = connectionRequest.post;
    const releaseSession = releaseRequest.delete;

    useEffect(() => {
        openRef.current = open;

        if (!open) {
            startedForCurrentOpening.current = false;

            return;
        }

        if (
            session !== null ||
            connectionRequest.processing ||
            startedForCurrentOpening.current
        ) {
            return;
        }

        startedForCurrentOpening.current = true;
        void startConnection(store.url()).then(async (response) => {
            if (!openRef.current) {
                await releaseSession(
                    releaseLiveSession.url(response.connection.id),
                );

                return;
            }

            setSession(response);
        });
    }, [
        connectionRequest.processing,
        open,
        releaseSession,
        session,
        startConnection,
    ]);

    const connectionId =
        session?.connection.id ?? groceryPreparation.connection?.id ?? null;
    const consent = session?.consent ?? groceryPreparation.consent;
    const requiresStandingConsent =
        session?.connection.requires_standing_consent ??
        !groceryPreparation.has_standing_consent;
    const error =
        Object.values(connectionRequest.errors)[0] ??
        Object.values(verificationRequest.errors)[0] ??
        null;

    const finishConnection = async () => {
        if (
            connectionId === null ||
            (requiresStandingConsent &&
                !verificationRequest.data.standing_consent)
        ) {
            return;
        }

        await verificationRequest.post(verify.url(connectionId));
        onOpenChange(false);
        setSession(null);
        verificationRequest.reset();
        router.reload({
            only: ['workspace', 'notifications'],
        });
    };

    const closeConnection = async () => {
        openRef.current = false;

        if (connectionId !== null && session !== null) {
            await releaseSession(releaseLiveSession.url(connectionId));
        }

        setSession(null);
        verificationRequest.reset();
        onOpenChange(false);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (nextOpen) {
                    onOpenChange(true);
                } else {
                    void closeConnection();
                }
            }}
        >
            <DialogContent className="max-h-[calc(100svh-2rem)] overflow-y-auto p-0 sm:max-w-3xl">
                <DialogHeader className="px-5 pt-5 sm:px-6 sm:pt-6">
                    <DialogTitle>
                        {requiresStandingConsent
                            ? 'Connect Coles'
                            : 'Continue with Coles'}
                    </DialogTitle>
                    <DialogDescription>
                        {requiresStandingConsent
                            ? 'Sign in directly inside the private browser below. Chef does not receive or store your password.'
                            : 'Continue your Coles sign-in in the private browser below. Your existing basket-preparation consent remains in place.'}
                    </DialogDescription>
                </DialogHeader>

                {connectionRequest.processing && (
                    <div
                        className="flex min-h-72 items-center justify-center gap-2 text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        <LoaderCircle className="size-4 animate-spin" />
                        Opening a private Coles session…
                    </div>
                )}

                {session !== null && (
                    <div className="space-y-5 px-5 sm:px-6">
                        <div className="overflow-hidden rounded-xl border bg-muted/30">
                            <iframe
                                src={session.session.live_view_url}
                                title="Secure Coles sign-in"
                                className="h-[48svh] min-h-80 w-full bg-white"
                                referrerPolicy="no-referrer"
                                allow="clipboard-read; clipboard-write"
                            />
                        </div>

                        {requiresStandingConsent && (
                            <section className="rounded-xl border p-4">
                                <div className="flex gap-3">
                                    <ShieldAlert className="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-400" />
                                    <div className="min-w-0 text-sm leading-6">
                                        <p className="font-medium">
                                            Review before granting standing
                                            consent
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {consent?.disclosure}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                            <a
                                                href={
                                                    consent?.links
                                                        .coles_online_safety
                                                }
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                                            >
                                                Coles online safety
                                                <ExternalLink className="size-3" />
                                            </a>
                                            <a
                                                href={
                                                    consent?.links
                                                        .coles_customer_agreement
                                                }
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                                            >
                                                Coles Customer Agreement
                                                <ExternalLink className="size-3" />
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <label className="mt-4 flex cursor-pointer items-start gap-3 border-t pt-4 text-sm leading-5">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5 size-4 rounded border-input accent-primary"
                                        checked={
                                            verificationRequest.data
                                                .standing_consent
                                        }
                                        onChange={(event) =>
                                            verificationRequest.setData(
                                                'standing_consent',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        I am the Coles account owner and
                                        authorise Chef to replace my current
                                        basket after future approved plans.
                                        Checkout and payment remain mine.
                                    </span>
                                </label>
                            </section>
                        )}
                    </div>
                )}

                {error !== null && (
                    <p
                        role="alert"
                        className="mx-5 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive sm:mx-6"
                    >
                        {String(error)}
                    </p>
                )}

                <DialogFooter className="border-t px-5 py-4 sm:px-6">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => void closeConnection()}
                    >
                        Not now
                    </Button>
                    {session !== null && (
                        <Button
                            type="button"
                            disabled={
                                (requiresStandingConsent &&
                                    !verificationRequest.data
                                        .standing_consent) ||
                                verificationRequest.processing
                            }
                            onClick={() => void finishConnection()}
                        >
                            {verificationRequest.processing ? (
                                <LoaderCircle className="animate-spin" />
                            ) : (
                                <CheckCircle2 />
                            )}
                            {requiresStandingConsent
                                ? 'Allow Chef & continue'
                                : 'Continue with Coles'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
