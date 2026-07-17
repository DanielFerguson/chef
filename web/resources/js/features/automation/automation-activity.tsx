import { router, useForm, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    Ban,
    Check,
    CircleAlert,
    CirclePause,
    ExternalLink,
    Link2,
    LoaderCircle,
    MonitorUp,
    Play,
    Store,
    Unplug,
} from 'lucide-react';
import { useEffect } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AutomationRun,
    AutomationRunStatus,
    AutomationWorkspace,
} from '@/features/shopping/types';

const statusLabels: Record<AutomationRunStatus, string> = {
    awaiting_browser: 'Waiting for your retailer tab',
    queued: 'Preparing the next action',
    processing: 'Inspecting the retailer page',
    executing: 'Adding products',
    awaiting_approval: 'Waiting for your decision',
    paused: 'Paused',
    takeover: 'Manual control',
    awaiting_review: 'Cart ready for review',
    completed: 'Handoff complete',
    failed: 'Stopped safely',
    cancelled: 'Cancelled',
    expired: 'Expired',
};

const activeStatuses: AutomationRunStatus[] = [
    'awaiting_browser',
    'queued',
    'processing',
    'executing',
    'awaiting_approval',
    'paused',
    'takeover',
];

const currencyFormatter = new Intl.NumberFormat('en-AU', {
    style: 'currency',
    currency: 'AUD',
});

function AutomationBroadcastListener({ teamId }: { teamId: number }) {
    useEcho<Record<string, unknown>>(
        `teams.${teamId}`,
        '.automation.run.updated',
        () => router.reload({ only: ['workspace'] }),
        [teamId],
    );

    return null;
}

function RunControl({
    run,
    control,
    children,
    variant = 'outline',
}: {
    run: AutomationRun;
    control: 'pause' | 'resume' | 'cancel' | 'takeover' | 'complete';
    children: React.ReactNode;
    variant?: 'default' | 'outline' | 'ghost' | 'destructive';
}) {
    return (
        <Button
            size="sm"
            variant={variant}
            onClick={() =>
                router.put(
                    `/automation-runs/${run.uuid}`,
                    { control },
                    { preserveScroll: true },
                )
            }
        >
            {children}
        </Button>
    );
}

function ApprovalCard({
    approval,
}: {
    approval: AutomationRun['approvals'][number];
}) {
    return (
        <article className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <p className="flex items-center gap-2 text-sm font-medium">
                <CircleAlert className="size-4" /> {approval.proposed_action}
            </p>
            <p className="mt-1 text-xs leading-5">{approval.consequence}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button
                    size="sm"
                    onClick={() =>
                        router.put(
                            `/automation-approvals/${approval.id}`,
                            { decision: 'approve' },
                            { preserveScroll: true },
                        )
                    }
                >
                    <Check /> Approve this action
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.put(
                            `/automation-approvals/${approval.id}`,
                            { decision: 'reject' },
                            { preserveScroll: true },
                        )
                    }
                >
                    <Ban /> Reject and take over
                </Button>
            </div>
        </article>
    );
}

function Reconciliation({ run }: { run: AutomationRun }) {
    if (run.reconciliations.length === 0) {
        return null;
    }

    const total = run.reconciliations.reduce(
        (sum, line) => sum + (line.total_price ?? 0),
        0,
    );

    return (
        <div className="mt-4 border-t pt-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">Prepared cart</p>
                <span className="text-sm tabular-nums">
                    {currencyFormatter.format(total)}
                </span>
            </div>
            <ul className="mt-2 divide-y text-xs">
                {run.reconciliations.map((line) => (
                    <li
                        key={line.id}
                        className="flex items-start justify-between gap-4 py-2"
                    >
                        <span>
                            <strong className="font-medium">
                                {line.intended_name}
                            </strong>
                            <span className="mt-0.5 block text-muted-foreground">
                                {line.product_name ??
                                    (line.status === 'unavailable'
                                        ? 'Unavailable'
                                        : 'Needs your choice')}
                                {line.status === 'substituted' &&
                                    ' · substitution'}
                            </span>
                        </span>
                        <Badge variant="secondary">{line.status}</Badge>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AutomationRunActivity({ run }: { run: AutomationRun }) {
    const pendingApprovals = run.approvals.filter(
        (approval) => approval.status === 'pending',
    );
    const canPause = [
        'queued',
        'processing',
        'executing',
        'awaiting_browser',
    ].includes(run.status);

    return (
        <section className="mt-4 rounded-xl border p-4" aria-live="polite">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="flex items-center gap-2 text-sm font-medium">
                        {activeStatuses.includes(run.status) &&
                        !['paused', 'takeover'].includes(run.status) ? (
                            <LoaderCircle className="size-4 animate-spin" />
                        ) : (
                            <Store className="size-4" />
                        )}
                        {statusLabels[run.status]}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {run.retailer.name} · frozen list revision{' '}
                        {run.shopping_list_revision} ·{' '}
                        {run.browser_connection.name ?? 'Chef extension'}
                    </p>
                </div>
                <Badge variant="secondary">
                    {run.progress.added} added · {run.progress.unresolved} need
                    attention
                </Badge>
            </div>
            {run.pause_reason && (
                <p className="mt-3 text-xs leading-5 text-muted-foreground">
                    {run.pause_reason}
                </p>
            )}
            {run.error_message && (
                <p className="mt-3 text-xs text-destructive" role="alert">
                    {run.error_message}
                </p>
            )}
            {pendingApprovals.length > 0 && (
                <div className="mt-4 space-y-3">
                    {pendingApprovals.map((approval) => (
                        <ApprovalCard key={approval.id} approval={approval} />
                    ))}
                </div>
            )}
            <Reconciliation run={run} />
            <div className="mt-4 flex flex-wrap gap-2">
                {canPause && (
                    <RunControl run={run} control="pause">
                        <CirclePause /> Pause
                    </RunControl>
                )}
                {['paused', 'takeover'].includes(run.status) && (
                    <RunControl run={run} control="resume">
                        <Play /> Resume Chef
                    </RunControl>
                )}
                {run.status === 'awaiting_review' && (
                    <RunControl run={run} control="complete" variant="default">
                        <ExternalLink /> Finish and take over
                    </RunControl>
                )}
                {activeStatuses.includes(run.status) &&
                    run.status !== 'takeover' && (
                        <RunControl run={run} control="takeover">
                            <MonitorUp /> Take manual control
                        </RunControl>
                    )}
                {activeStatuses.includes(run.status) && (
                    <RunControl run={run} control="cancel" variant="ghost">
                        Cancel
                    </RunControl>
                )}
            </div>
            <p className="mt-4 text-xs text-muted-foreground">
                Chef can prepare this cart, but checkout, address changes,
                authentication, delivery choices, and payment stay with you.
            </p>
        </section>
    );
}

export function AutomationActivity({
    automation,
    listId,
    revision,
    retailers,
}: {
    automation: AutomationWorkspace;
    listId: number;
    revision: number;
    retailers: { id: number; name: string; slug: string }[];
}) {
    const { auth } = usePage().props;
    const activeConnections = automation.connections.filter(
        (connection) => connection.status === 'active',
    );
    const pendingConnections = automation.connections.filter(
        (connection) => connection.status === 'pending',
    );
    const latestRun = automation.runs[0] ?? null;
    const form = useForm({
        retailer_id: retailers[0]?.id.toString() ?? '',
        browser_connection_uuid: activeConnections[0]?.uuid ?? '',
        expected_revision: revision,
    });
    const shouldPoll =
        pendingConnections.length > 0 ||
        Boolean(latestRun && activeStatuses.includes(latestRun.status));

    useEffect(() => {
        if (!shouldPoll) {
            return;
        }

        const interval = window.setInterval(
            () => router.reload({ only: ['workspace'] }),
            2000,
        );

        return () => window.clearInterval(interval);
    }, [shouldPoll]);

    return (
        <section className="mt-8 border-t pt-6" aria-labelledby="cart-handoff">
            {import.meta.env.VITE_REVERB_APP_KEY && auth.currentTeam && (
                <AutomationBroadcastListener teamId={auth.currentTeam.id} />
            )}
            <div>
                <h2
                    id="cart-handoff"
                    className="flex items-center gap-2 text-sm font-medium"
                >
                    <MonitorUp className="size-4" /> Prepare retailer cart
                </h2>
                <p className="mt-1 max-w-2xl text-xs leading-5 text-muted-foreground">
                    Chef uses only a retailer tab you select. The exact list
                    revision is frozen for the run, and every consequential
                    decision stays visible here.
                </p>
            </div>

            {activeConnections.length === 0 &&
                automation.can_manage_integrations && (
                    <div className="mt-4 rounded-xl bg-muted/40 p-4">
                        <p className="text-sm font-medium">
                            Pair the Chef extension
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Open the Chef Chrome extension, then enter the
                            temporary code shown here. The extension receives no
                            OpenAI key.
                        </p>
                        {automation.pairing_code ? (
                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                <code className="rounded-md bg-background px-3 py-2 text-lg tracking-widest">
                                    {automation.pairing_code}
                                </code>
                                <span className="text-xs text-muted-foreground">
                                    {pendingConnections[0]
                                        ? `Expires at ${new Date(pendingConnections[0].expires_at).toLocaleTimeString('en-AU', { hour: 'numeric', minute: '2-digit' })}`
                                        : 'Expires shortly'}
                                </span>
                            </div>
                        ) : (
                            <Button
                                className="mt-3"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        '/browser-connections',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Link2 /> Create pairing code
                            </Button>
                        )}
                    </div>
                )}

            {activeConnections.length === 0 &&
                !automation.can_manage_integrations && (
                    <div className="mt-4 rounded-xl bg-muted/40 p-4">
                        <p className="text-sm font-medium">
                            A household owner needs to pair the Chef extension
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Once paired, household members can use the
                            authorised browser connection to prepare a shopping
                            cart.
                        </p>
                    </div>
                )}

            {activeConnections.length > 0 &&
                (!latestRun ||
                    ['completed', 'failed', 'cancelled', 'expired'].includes(
                        latestRun.status,
                    )) && (
                    <form
                        className="mt-4 grid gap-3 rounded-xl bg-muted/40 p-4 sm:grid-cols-[1fr_1fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                retailer_id: Number(data.retailer_id),
                                browser_connection_uuid:
                                    data.browser_connection_uuid ||
                                    activeConnections[0]?.uuid ||
                                    '',
                                expected_revision: revision,
                            }));
                            form.post(
                                `/shopping-lists/${listId}/automation-runs`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Select
                            value={form.data.retailer_id}
                            onValueChange={(value) =>
                                form.setData('retailer_id', value)
                            }
                        >
                            <SelectTrigger aria-label="Cart retailer">
                                <SelectValue placeholder="Retailer" />
                            </SelectTrigger>
                            <SelectContent>
                                {retailers.map((retailer) => (
                                    <SelectItem
                                        key={retailer.id}
                                        value={retailer.id.toString()}
                                    >
                                        {retailer.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={
                                form.data.browser_connection_uuid ||
                                activeConnections[0]?.uuid ||
                                ''
                            }
                            onValueChange={(value) =>
                                form.setData('browser_connection_uuid', value)
                            }
                        >
                            <SelectTrigger aria-label="Paired browser">
                                <SelectValue placeholder="Paired browser" />
                            </SelectTrigger>
                            <SelectContent>
                                {activeConnections.map((connection) => (
                                    <SelectItem
                                        key={connection.uuid}
                                        value={connection.uuid}
                                    >
                                        {connection.name ?? 'Chef extension'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button disabled={form.processing}>
                            <Store /> Prepare cart
                        </Button>
                        {Object.values(form.errors)[0] && (
                            <p
                                className="text-xs text-destructive sm:col-span-3"
                                role="alert"
                            >
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                    </form>
                )}

            {latestRun && <AutomationRunActivity run={latestRun} />}

            {activeConnections.length > 0 && (
                <details className="mt-4 text-xs text-muted-foreground">
                    <summary className="cursor-pointer">
                        Paired browsers
                    </summary>
                    <ul className="mt-2 space-y-2">
                        {activeConnections.map((connection) => (
                            <li
                                key={connection.uuid}
                                className="flex items-center justify-between gap-3"
                            >
                                <span>
                                    {connection.name ?? 'Chef extension'} ·{' '}
                                    {connection.last_seen_at
                                        ? `seen ${new Date(connection.last_seen_at).toLocaleString('en-AU')}`
                                        : 'not seen yet'}
                                </span>
                                {automation.can_manage_integrations && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                `/browser-connections/${connection.uuid}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Unplug /> Revoke
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
