import { UsersRound } from 'lucide-react';

type PendingInvitation = {
    householdName: string;
    inviterName: string | null;
    url: string;
};

export default function PendingInvitationBanner({
    invitation,
}: {
    invitation: PendingInvitation;
}) {
    return (
        <div
            className="rounded-xl border border-primary/25 bg-primary/5 p-4"
            data-test="pending-invitation-banner"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <UsersRound className="size-4" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <p className="font-medium">
                        You’ve been invited to join {invitation.householdName}
                    </p>
                    <p className="mt-1 text-sm leading-5 text-muted-foreground">
                        {invitation.inviterName
                            ? `${invitation.inviterName} invited you to plan meals together.`
                            : 'Sign in or create an account to continue to the invitation.'}
                    </p>
                </div>
            </div>
        </div>
    );
}
