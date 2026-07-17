import { Head } from '@inertiajs/react';
import PublicInformationLayout from '@/layouts/public-information-layout';

type Props = {
    operator: string | null;
    supportEmail: string | null;
    contactAddress: string | null;
    processingCountries: string | null;
    conversationDays: number;
    screenshotHours: number;
    auditDays: number;
};

export default function Privacy({
    operator,
    supportEmail,
    contactAddress,
    processingCountries,
    conversationDays,
    screenshotHours,
    auditDays,
}: Props) {
    return (
        <PublicInformationLayout>
            <Head title="Privacy policy" />
            <article className="typeset typeset-docs max-w-none">
                <p className="text-sm text-muted-foreground">
                    Effective 17 July 2026
                </p>
                <h1>Privacy policy</h1>
                <p>
                    {operator ?? 'The configured service operator'} operates
                    Chef. This policy explains the personal information Chef
                    collects, why it is used, who receives it, and the choices
                    available to you.
                </p>

                <h2>Information Chef handles</h2>
                <p>
                    Chef stores account and family membership details, the
                    people and preferences you choose to enter, explicit allergy
                    and safety constraints, conversations, meal plans, recipes,
                    shopping lists, cooking feedback, consent choices, and
                    security and automation audit records. Voice audio is sent
                    to the Realtime provider during an active session and is not
                    retained by Chef. Retailer pages and short-lived screenshots
                    are processed only during an approved cart preparation.
                </p>

                <h2>How information is used</h2>
                <p>
                    Chef uses this information to provide and secure the
                    service, maintain the structured family plan, generate AI
                    responses and recipe drafts, prepare retailer carts for
                    human review, diagnose failures, enforce limits, and answer
                    support or privacy requests. Product analytics and
                    attributed beta research are off unless you opt in, and can
                    be revoked from Data and privacy settings.
                </p>

                <h2>Service providers and overseas processing</h2>
                <p>
                    Chef uses contracted hosting, email, monitoring, and AI
                    providers. AI prompts may include relevant household context
                    needed for the requested task. Provider-side storage is
                    disabled where supported. Processing may occur in{' '}
                    {processingCountries ??
                        'the locations disclosed for the deployed service'}
                    . Chef does not sell personal information. Retailer
                    credentials stay in your browser and are not collected by
                    Chef.
                </p>

                <h2>Retention and security</h2>
                <p>
                    Browser screenshots are deleted within {screenshotHours}{' '}
                    hours. Conversation content is redacted after{' '}
                    {conversationDays} days. Content-free operational and safety
                    records are retained for up to {auditDays} days. Deleting a
                    family removes its structured data and retained files. Chef
                    uses tenant authorisation, encrypted transport, encrypted
                    production sessions, private object storage, scoped browser
                    grants, rate limits, and monitored backups. No internet
                    service can promise absolute security.
                </p>

                <h2>Access, correction, export, and deletion</h2>
                <p>
                    Family data can be corrected in the product, exported as
                    structured JSON, and deleted by a family owner. Account
                    deletion is available in profile settings. Contact us if you
                    need access or correction in another form, cannot use those
                    controls, or want to make a privacy complaint. We will
                    acknowledge the request, verify authority, investigate it,
                    and explain the outcome.
                </p>

                <h2>Children and other family members</h2>
                <p>
                    Account holders are responsible for having authority to add
                    information about other family members. Do not enter
                    unnecessary sensitive details. Allergy information must be
                    entered and checked by a responsible person; Chef does not
                    infer it.
                </p>

                <h2>Contact</h2>
                <p>
                    {supportEmail ? (
                        <>
                            Email{' '}
                            <a href={`mailto:${supportEmail}`}>
                                {supportEmail}
                            </a>
                            .{' '}
                        </>
                    ) : (
                        'The deployed service publishes its support email here. '
                    )}
                    Postal contact:{' '}
                    {contactAddress ??
                        'the address configured for the deployed service'}
                    . We may update this policy when the product or its
                    providers change and will publish a new effective date.
                </p>
            </article>
        </PublicInformationLayout>
    );
}
