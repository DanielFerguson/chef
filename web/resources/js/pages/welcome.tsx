import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays, MessagesSquare, ShoppingBasket } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        title: 'Talk through the week',
        description: 'Plan naturally with typing, dictation, or voice.',
        icon: MessagesSquare,
    },
    {
        title: 'Keep one shared plan',
        description: 'Turn the conversation into a clear family calendar.',
        icon: CalendarDays,
    },
    {
        title: 'Shop with confidence',
        description:
            'Review a budget-aware list before Chef prepares the cart.',
        icon: ShoppingBasket,
    },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Plan dinner together" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="mx-auto flex max-w-6xl items-center justify-between px-5 py-5 sm:px-8">
                    <Link
                        href="/"
                        className="flex items-center gap-2.5 font-editorial text-lg font-medium"
                    >
                        <span className="flex size-8 items-center justify-center rounded-lg border bg-white">
                            <AppLogoIcon className="size-6" />
                        </span>
                        Chef
                    </Link>
                    <nav
                        className="flex items-center gap-2"
                        aria-label="Account"
                    >
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>Open Chef</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>Get started</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main>
                    <section className="mx-auto flex max-w-4xl flex-col items-center px-5 pt-24 pb-20 text-center sm:px-8 sm:pt-32">
                        <span className="mb-7 flex size-14 items-center justify-center rounded-2xl border bg-white">
                            <AppLogoIcon className="size-10" />
                        </span>
                        <h1 className="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-6xl">
                            Dinner planning that feels like a conversation
                        </h1>
                        <p className="mt-6 max-w-2xl text-base leading-7 text-pretty text-muted-foreground sm:text-lg">
                            Chef learns how your family eats, turns an ordinary
                            chat into a useful plan, and keeps shopping and
                            cooking close at hand.
                        </p>
                        <Button size="lg" className="mt-9" asChild>
                            <Link href={auth.user ? dashboard() : register()}>
                                {auth.user
                                    ? 'Open your workspace'
                                    : 'Plan your first week'}
                            </Link>
                        </Button>
                    </section>

                    <section className="mx-auto grid max-w-5xl gap-px overflow-hidden rounded-2xl border bg-border sm:grid-cols-3">
                        {features.map((feature) => (
                            <div
                                key={feature.title}
                                className="bg-card p-7 text-left"
                            >
                                <feature.icon className="mb-5 size-5 text-primary" />
                                <h2 className="text-sm font-medium">
                                    {feature.title}
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    {feature.description}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>
                <footer className="mx-auto mt-20 max-w-6xl border-t px-5 py-8 sm:px-8">
                    <nav
                        className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground"
                        aria-label="Legal and support"
                    >
                        <Link className="-my-1 py-1" href="/privacy">
                            Privacy
                        </Link>
                        <Link className="-my-1 py-1" href="/terms">
                            Terms
                        </Link>
                        <Link
                            className="-my-1 py-1"
                            href="/security-and-privacy"
                        >
                            Security
                        </Link>
                        <Link className="-my-1 py-1" href="/help">
                            Help
                        </Link>
                        <Link className="-my-1 py-1" href="/release-notes">
                            Release notes
                        </Link>
                    </nav>
                </footer>
            </div>
        </>
    );
}
