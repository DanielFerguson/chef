import { Head } from '@inertiajs/react';
import PublicInformationLayout from '@/layouts/public-information-layout';

export default function Terms({
    operator,
    supportEmail,
}: {
    operator: string | null;
    supportEmail: string | null;
}) {
    return (
        <PublicInformationLayout>
            <Head title="Terms of use" />
            <article className="typeset typeset-docs max-w-none">
                <p className="text-sm text-muted-foreground">
                    Effective 17 July 2026
                </p>
                <h1>Terms of use</h1>
                <p>
                    These terms apply when you use Chef, operated by{' '}
                    {operator ?? 'the configured service operator'}. By creating
                    an account you agree to them.
                </p>

                <h2>What Chef does</h2>
                <p>
                    Chef helps families plan meals, prepare shopping lists,
                    follow recipes, and prepare retailer carts for review. AI
                    output can be incomplete or wrong. You remain responsible
                    for reviewing plans, ingredients, quantities, prices,
                    recipes, safety information, and retailer choices.
                </p>

                <h2>Food and allergy safety</h2>
                <p>
                    Chef is not medical, dietary, or food-safety advice. Never
                    rely on Chef to infer an allergy. A responsible person must
                    enter and verify constraints, check labels and cross-contact
                    warnings, and decide whether food is suitable. Seek
                    qualified professional advice when needed.
                </p>

                <h2>Retailers, orders, and payment</h2>
                <p>
                    Chef may prepare a cart in a retailer tab you explicitly
                    connect. Chef does not submit checkout, payment, delivery,
                    address, authentication, or acceptance of retailer terms.
                    You must review and complete any order yourself. Retailer
                    availability, price, product data, and terms are controlled
                    by the retailer.
                </p>

                <h2>Your account and family</h2>
                <p>
                    Keep account and browser-connection credentials secure. Only
                    add people or information you are authorised to manage.
                    Family owners control shared data and may delete it for all
                    members. Tell us promptly about suspected unauthorised use.
                </p>

                <h2>Acceptable use</h2>
                <p>
                    Do not misuse Chef, evade limits, probe another family’s
                    data, disrupt the service, automate prohibited purchases,
                    upload unlawful content, or use the service to harm another
                    person. We may restrict or suspend access needed to protect
                    users, providers, retailers, or the service.
                </p>

                <h2>Availability and changes</h2>
                <p>
                    Chef may change as features are improved. We aim to provide
                    a reliable service but cannot guarantee uninterrupted or
                    error-free operation. Material term changes will be
                    published with a new effective date.
                </p>

                <h2>Consumer rights</h2>
                <p>
                    Nothing in these terms excludes rights or remedies that
                    cannot lawfully be excluded. Any limitations apply only to
                    the extent permitted by law.
                </p>

                <h2>Contact</h2>
                <p>
                    {supportEmail ? (
                        <>
                            Questions or notices can be sent to{' '}
                            <a href={`mailto:${supportEmail}`}>
                                {supportEmail}
                            </a>
                            .
                        </>
                    ) : (
                        'The deployed service publishes its support contact here.'
                    )}
                </p>
            </article>
        </PublicInformationLayout>
    );
}
