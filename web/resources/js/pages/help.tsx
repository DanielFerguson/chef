import { Head, Link } from '@inertiajs/react';
import PublicInformationLayout from '@/layouts/public-information-layout';

export default function Help({
    supportEmail,
}: {
    supportEmail: string | null;
}) {
    return (
        <PublicInformationLayout>
            <Head title="Help" />
            <article className="typeset typeset-docs max-w-none">
                <h1>Help with Chef</h1>
                <h2>Plan your first meals</h2>
                <ol>
                    <li>Create a plan for the dates you need.</li>
                    <li>
                        Tell Chef who is eating and any explicit allergies or
                        safety constraints.
                    </li>
                    <li>Review and directly edit the proposed meal slots.</li>
                    <li>Confirm the plan when it reflects your family.</li>
                </ol>
                <h2>Shop</h2>
                <p>
                    Generate the structured shopping list, review included and
                    pantry items, and choose a retailer. The browser extension
                    can prepare a cart only after you connect it. Watch the
                    audit panel, pause or take over when needed, and complete
                    checkout yourself.
                </p>
                <h2>Cook</h2>
                <p>
                    Open a planned meal, confirm servings, and follow the
                    accessible step list. Progress is saved. After eating,
                    record feedback so future plans can improve.
                </p>
                <h2>Voice is optional</h2>
                <p>
                    Typed Chef supports the complete workflow. If voice fails,
                    reconnect once or continue typing. Mute or stop the session
                    to revoke Chef’s active microphone access; browser-level
                    permission remains under your browser settings.
                </p>
                <h2>Data and account controls</h2>
                <p>
                    Use{' '}
                    <Link href="/settings/data-privacy">Data and privacy</Link>{' '}
                    to manage optional consent, view usage and automation audit
                    events, export family data, or delete a family. Profile
                    settings contain account deletion.
                </p>
                <h2>Contact support</h2>
                <p>
                    {supportEmail ? (
                        <>
                            Email{' '}
                            <a href={`mailto:${supportEmail}`}>
                                {supportEmail}
                            </a>{' '}
                            with what you were trying to do, the page,
                            approximate time, and any visible error.{' '}
                        </>
                    ) : (
                        'Use the support address published by the deployed service. '
                    )}
                    Do not send passwords, retailer credentials, API keys, or
                    allergy details unless essential.
                </p>
            </article>
        </PublicInformationLayout>
    );
}
