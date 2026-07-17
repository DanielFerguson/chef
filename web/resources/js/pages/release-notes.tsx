import { Head } from '@inertiajs/react';
import PublicInformationLayout from '@/layouts/public-information-layout';

export default function ReleaseNotes() {
    return (
        <PublicInformationLayout>
            <Head title="Release notes" />
            <article className="typeset typeset-docs max-w-none">
                <h1>Release notes</h1>
                <p className="text-sm text-muted-foreground">
                    Version 1 release candidate · 17 July 2026
                </p>
                <p>
                    Chef’s first release candidate supports collaborative family
                    onboarding, conversational meal planning, structured plans
                    and recipes, budget-aware shopping lists, reviewed
                    Woolworths and Coles cart preparation, cooking progress and
                    feedback, and optional native voice.
                </p>
                <h2>Safety and control</h2>
                <ul>
                    <li>Checkout and payment remain human actions.</li>
                    <li>Allergies are explicit and never inferred.</li>
                    <li>
                        Retailer automation includes approvals, pause, cancel,
                        manual takeover, expiry, and reconciliation.
                    </li>
                    <li>
                        Data export, family deletion, consent, retention, usage
                        limits, and operational audit controls are available.
                    </li>
                </ul>
                <p>
                    Public version 1 will be named here only after the staging,
                    retailer-account, real-microphone, backup-restore, and
                    private-beta release gates pass.
                </p>
            </article>
        </PublicInformationLayout>
    );
}
