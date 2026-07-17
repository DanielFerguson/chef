import { Head, router, useForm } from '@inertiajs/react';
import { Download, ShieldCheck, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ConsentStatus = 'granted' | 'declined' | 'revoked';

type Props = {
    team: { id: number; name: string };
    consents: Partial<
        Record<
            'product_analytics' | 'beta_research',
            { status: ConsentStatus; occurred_at: string }
        >
    >;
    permissions: {
        active_browser_connections: number;
        active_voice_sessions: number;
    };
    canDeleteTeam: boolean;
    support: {
        email: string | null;
        privacy_url: string | null;
        terms_url: string | null;
    };
    retention: {
        conversation_days: number;
        screenshot_hours: number;
        audit_days: number;
    };
    usage: {
        ai_requests: number;
        tokens: number;
        estimated_cost_usd: number;
        automation_runs: number;
        recent_automation: Array<{
            name: string;
            status: string;
            occurred_at: string;
            metadata: Record<string, string | number | boolean | null> | null;
        }>;
    };
    quotas: {
        tokens_per_month: number;
        cost_usd_per_month: number;
        automation_runs_per_day: number;
        voice_sessions_per_day: number;
    };
};

function ConsentChoice({
    kind,
    title,
    description,
    consent,
}: {
    kind: 'product_analytics' | 'beta_research';
    title: string;
    description: string;
    consent?: { status: ConsentStatus; occurred_at: string };
}) {
    const update = (status: ConsentStatus) =>
        router.put(
            '/settings/consent',
            { kind, status },
            { preserveScroll: true },
        );

    return (
        <div className="border-t py-4 first:border-t-0 first:pt-0">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="max-w-lg">
                    <p className="text-sm font-medium">{title}</p>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        {description}
                    </p>
                </div>
                <Badge variant="secondary">
                    {consent?.status === 'granted'
                        ? 'Allowed'
                        : consent?.status === 'declined'
                          ? 'Declined'
                          : consent?.status === 'revoked'
                            ? 'Revoked'
                            : 'Not chosen'}
                </Badge>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant={
                        consent?.status === 'granted' ? 'default' : 'outline'
                    }
                    onClick={() => update('granted')}
                >
                    Allow
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        update(
                            consent?.status === 'granted'
                                ? 'revoked'
                                : 'declined',
                        )
                    }
                >
                    {consent?.status === 'granted' ? 'Revoke' : 'Decline'}
                </Button>
            </div>
        </div>
    );
}

export default function DataPrivacy({
    team,
    consents,
    permissions,
    canDeleteTeam,
    support,
    retention,
    usage,
    quotas,
}: Props) {
    const deleteForm = useForm({ team_name: '', password: '' });

    return (
        <>
            <Head title="Data and privacy" />
            <h1 className="sr-only">Data and privacy</h1>

            <div className="space-y-10">
                <section className="space-y-5">
                    <Heading
                        id="optional-consent"
                        variant="small"
                        title="Data and privacy"
                        description={`Review choices and retained data for ${team.name}`}
                    />

                    <div className="rounded-lg border p-4">
                        <div className="flex items-start gap-3">
                            <ShieldCheck className="mt-0.5 size-5 text-primary" />
                            <div>
                                <p className="text-sm font-medium">
                                    Essential household processing
                                </p>
                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                    Chef stores plans, conversations, recipes,
                                    shopping activity, cooking feedback, and
                                    permission audits to provide the service.
                                    OpenAI storage stays disabled. Optional uses
                                    below are off until you allow them.
                                </p>
                                <div className="mt-3 flex flex-wrap gap-4 text-sm">
                                    {support.privacy_url && (
                                        <a
                                            className="underline underline-offset-4"
                                            href={support.privacy_url}
                                        >
                                            Privacy policy
                                        </a>
                                    )}
                                    {support.terms_url && (
                                        <a
                                            className="underline underline-offset-4"
                                            href={support.terms_url}
                                        >
                                            Terms
                                        </a>
                                    )}
                                    {support.email && (
                                        <a
                                            className="underline underline-offset-4"
                                            href={`mailto:${support.email}`}
                                        >
                                            Contact support
                                        </a>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="optional-consent">
                    <Heading
                        id="permissions"
                        variant="small"
                        title="Optional uses"
                        description="These choices do not block planning, shopping, cooking, or typed access"
                    />
                    <div className="mt-5 rounded-lg border p-4">
                        <ConsentChoice
                            kind="product_analytics"
                            title="Product analytics"
                            description="Measure which Chef stages are reached and where workflows fail. Conversation text, allergies, screenshots, retailer credentials, and recipe content are excluded."
                            consent={consents.product_analytics}
                        />
                        <ConsentChoice
                            kind="beta_research"
                            title="Attributed beta research"
                            description="Allow your submitted beta feedback to be reviewed with your account and family context. Revoking this stops future attributed research use."
                            consent={consents.beta_research}
                        />
                    </div>
                </section>

                <section className="space-y-5" aria-labelledby="permissions">
                    <Heading
                        id="usage-audit"
                        variant="small"
                        title="Permissions and retained access"
                        description="Microphone and retailer access are requested only when used"
                    />
                    <div className="grid gap-3 text-sm sm:grid-cols-2">
                        <div className="rounded-lg border p-4">
                            <p className="font-medium">Retailer browsers</p>
                            <p className="mt-1 text-muted-foreground">
                                {permissions.active_browser_connections} active
                                household grant
                                {permissions.active_browser_connections === 1
                                    ? ''
                                    : 's'}
                                . Revoke them from the shopping handoff.
                            </p>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="font-medium">Microphone sessions</p>
                            <p className="mt-1 text-muted-foreground">
                                {permissions.active_voice_sessions} active Chef
                                voice session
                                {permissions.active_voice_sessions === 1
                                    ? ''
                                    : 's'}
                                . Ending voice revokes Chef's session; browser
                                permission is managed in browser settings.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="space-y-5" aria-labelledby="usage-audit">
                    <Heading
                        id="retention"
                        variant="small"
                        title="Usage and automation audit"
                        description="Content-free operational totals for this family; prompts, conversation text, screenshots, and credentials are never included"
                    />
                    <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">AI requests</dt>
                            <dd className="mt-1 text-muted-foreground">
                                {usage.ai_requests.toLocaleString()} this month
                            </dd>
                        </div>
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">AI tokens</dt>
                            <dd className="mt-1 text-muted-foreground">
                                {usage.tokens.toLocaleString()} of{' '}
                                {quotas.tokens_per_month.toLocaleString()}
                            </dd>
                        </div>
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">Estimated AI cost</dt>
                            <dd className="mt-1 text-muted-foreground">
                                ${usage.estimated_cost_usd.toFixed(4)} of $
                                {quotas.cost_usd_per_month.toFixed(2)} USD
                            </dd>
                        </div>
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">Cart preparations</dt>
                            <dd className="mt-1 text-muted-foreground">
                                {usage.automation_runs} this month; limit{' '}
                                {quotas.automation_runs_per_day} per day
                            </dd>
                        </div>
                    </dl>
                    {usage.recent_automation.length > 0 && (
                        <div className="overflow-hidden rounded-lg border">
                            <div className="border-b px-4 py-3 text-sm font-medium">
                                Recent retailer automation events
                            </div>
                            <ul className="divide-y text-sm">
                                {usage.recent_automation.map((event, index) => (
                                    <li
                                        className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                                        key={`${event.occurred_at}-${event.name}-${index}`}
                                    >
                                        <span>
                                            {event.name.replaceAll('_', ' ')}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {event.status} ·{' '}
                                            {new Date(
                                                event.occurred_at,
                                            ).toLocaleString()}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Voice is limited to {quotas.voice_sessions_per_day}{' '}
                        sessions per family each day. Typed planning remains
                        available when voice or retailer automation reaches a
                        limit.
                    </p>
                </section>

                <section className="space-y-5" aria-labelledby="retention">
                    <Heading
                        id="export-data"
                        variant="small"
                        title="Retention"
                        description="Chef keeps durable household state separately from temporary or conversational artifacts"
                    />
                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">
                                Conversation content
                            </dt>
                            <dd className="mt-1 text-muted-foreground">
                                {retention.conversation_days} days, then content
                                is redacted while structured plans remain.
                            </dd>
                        </div>
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">Browser screenshots</dt>
                            <dd className="mt-1 text-muted-foreground">
                                Up to {retention.screenshot_hours} hours, then
                                the private file is deleted.
                            </dd>
                        </div>
                        <div className="rounded-lg border p-4">
                            <dt className="font-medium">
                                Safety and audit records
                            </dt>
                            <dd className="mt-1 text-muted-foreground">
                                Up to {retention.audit_days} days unless the
                                family is deleted earlier.
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="space-y-5" aria-labelledby="export-data">
                    <Heading
                        variant="small"
                        title="Export family data"
                        description="Download a structured JSON copy without credentials or retained screenshots"
                    />
                    <Button asChild variant="outline">
                        <a href="/settings/data-export">
                            <Download /> Download JSON export
                        </a>
                    </Button>
                </section>

                {canDeleteTeam && (
                    <section
                        className="space-y-5"
                        aria-labelledby="delete-family"
                    >
                        <Heading
                            id="delete-family"
                            variant="small"
                            title="Delete family"
                            description="Permanently delete this family's plans, conversations, recipes, shopping, cooking, automation, and consent records"
                        />
                        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="destructive">
                                        <Trash2 /> Delete {team.name}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Delete {team.name}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        This removes the shared family data for
                                        every member and cannot be undone. Your
                                        account remains available.
                                    </DialogDescription>
                                    <form
                                        className="space-y-4"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            deleteForm.delete(
                                                '/settings/family',
                                                {
                                                    preserveScroll: true,
                                                },
                                            );
                                        }}
                                    >
                                        <div className="grid gap-2">
                                            <Label htmlFor="team_name">
                                                Enter {team.name}
                                            </Label>
                                            <Input
                                                id="team_name"
                                                value={
                                                    deleteForm.data.team_name
                                                }
                                                onChange={(event) =>
                                                    deleteForm.setData(
                                                        'team_name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    deleteForm.errors.team_name
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="delete_team_password">
                                                Current password
                                            </Label>
                                            <PasswordInput
                                                id="delete_team_password"
                                                value={deleteForm.data.password}
                                                onChange={(event) =>
                                                    deleteForm.setData(
                                                        'password',
                                                        event.target.value,
                                                    )
                                                }
                                                autoComplete="current-password"
                                            />
                                            <InputError
                                                message={
                                                    deleteForm.errors.password
                                                }
                                            />
                                        </div>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={deleteForm.processing}
                                            >
                                                Permanently delete family
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

DataPrivacy.layout = {
    breadcrumbs: [
        {
            title: 'Data and privacy',
            href: '/settings/data-privacy',
        },
    ],
};
