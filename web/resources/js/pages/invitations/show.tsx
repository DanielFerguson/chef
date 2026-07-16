import { Head, Link } from '@inertiajs/react';
import { MailCheck, UsersRound } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Invitation = {
    email: string;
    team: { id: number; name: string };
    inviter: { name: string } | null;
    person: { id: number; name: string } | null;
    accept_url: string;
    matches_user: boolean;
};

export default function InvitationShow({
    invitation,
}: {
    invitation: Invitation;
}) {
    return (
        <>
            <Head title={`Join ${invitation.team.name}`} />
            <main className="flex min-h-[calc(100vh-4rem)] items-center justify-center p-6">
                <div className="w-full max-w-md rounded-2xl border bg-card p-7 shadow-sm">
                    <span className="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <UsersRound className="size-5" />
                    </span>
                    <h1 className="mt-5 text-xl font-semibold">
                        Join {invitation.team.name}
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        {invitation.inviter?.name ?? 'A family member'} invited{' '}
                        {invitation.email} to plan meals together in Chef.
                    </p>
                    {invitation.person && (
                        <p className="mt-3 rounded-lg border bg-muted/50 p-3 text-sm leading-6">
                            Accepting links your account to{' '}
                            <strong>{invitation.person.name}</strong> and keeps
                            their existing meal history and preferences.
                        </p>
                    )}
                    {invitation.matches_user ? (
                        <Button asChild className="mt-6 w-full">
                            <Link
                                href={invitation.accept_url}
                                method="post"
                                as="button"
                            >
                                <MailCheck /> Accept invitation
                            </Link>
                        </Button>
                    ) : (
                        <p className="mt-6 rounded-lg border bg-muted/50 p-3 text-sm leading-6">
                            Sign in as <strong>{invitation.email}</strong> to
                            accept this invitation.
                        </p>
                    )}
                </div>
            </main>
        </>
    );
}
