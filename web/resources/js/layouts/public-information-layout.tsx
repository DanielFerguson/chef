import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

export default function PublicInformationLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b">
                <div className="mx-auto flex max-w-4xl items-center justify-between px-5 py-5 sm:px-8">
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
                        className="flex gap-4 text-sm text-muted-foreground"
                        aria-label="Information"
                    >
                        <Link className="-my-1 py-1" href="/help">
                            Help
                        </Link>
                        <Link
                            className="-my-1 py-1"
                            href="/security-and-privacy"
                        >
                            Security
                        </Link>
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-3xl px-5 py-14 sm:px-8 sm:py-20">
                {children}
            </main>
            <footer className="border-t">
                <nav
                    className="mx-auto flex max-w-4xl flex-wrap gap-x-5 gap-y-2 px-5 py-8 text-sm text-muted-foreground sm:px-8"
                    aria-label="Legal and support"
                >
                    <Link className="-my-1 py-1" href="/privacy">
                        Privacy
                    </Link>
                    <Link className="-my-1 py-1" href="/terms">
                        Terms
                    </Link>
                    <Link className="-my-1 py-1" href="/security-and-privacy">
                        Security and privacy
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
    );
}
