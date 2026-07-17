import { Head } from '@inertiajs/react';
import PublicInformationLayout from '@/layouts/public-information-layout';

export default function SecurityAndPrivacy({
    supportEmail,
}: {
    supportEmail: string | null;
}) {
    return (
        <PublicInformationLayout>
            <Head title="Security and privacy" />
            <article className="typeset typeset-docs max-w-none">
                <h1>Security and privacy by design</h1>
                <p>
                    Conversation helps express intent; Chef’s structured family
                    records remain the visible and editable source of truth.
                    Accounts and people are separate, and every family-owned
                    record is authorised against the current family.
                </p>
                <h2>AI boundaries</h2>
                <ul>
                    <li>OpenAI credentials remain on Chef’s server.</li>
                    <li>Provider-side response storage is disabled.</li>
                    <li>
                        Allergy and safety constraints are explicit and never
                        inferred.
                    </li>
                    <li>
                        Voice has mute, stop, permission-revocation, and typed
                        fallback paths.
                    </li>
                </ul>
                <h2>Retailer automation boundaries</h2>
                <ul>
                    <li>
                        A short-lived, family-scoped browser connection is
                        required.
                    </li>
                    <li>
                        Retailer pages and screenshots are treated as untrusted
                        input.
                    </li>
                    <li>
                        Checkout, payment, addresses, authentication, legal
                        terms, and order submission are prohibited.
                    </li>
                    <li>
                        Pause, cancel, manual takeover, approval, and an audit
                        trail remain available throughout a run.
                    </li>
                </ul>
                <h2>Reporting a concern</h2>
                <p>
                    Do not include passwords, API keys, retailer credentials, or
                    unnecessary family data.{' '}
                    {supportEmail ? (
                        <>
                            Email{' '}
                            <a href={`mailto:${supportEmail}`}>
                                {supportEmail}
                            </a>{' '}
                            with the affected page, approximate time, and a safe
                            description.
                        </>
                    ) : (
                        'Use the support contact published by the deployed service.'
                    )}
                </p>
            </article>
        </PublicInformationLayout>
    );
}
