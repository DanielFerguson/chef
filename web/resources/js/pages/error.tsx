import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleAlert } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';

const errors: Record<number, { title: string; description: string }> = {
    403: {
        title: 'That action is not available',
        description:
            'Your account or current family does not have permission for this action.',
    },
    404: {
        title: 'We could not find that page',
        description:
            'It may have moved, been deleted, or belong to a different family.',
    },
    419: {
        title: 'Your session expired',
        description:
            'Reload the page and try again. Your saved family data is unchanged.',
    },
    429: {
        title: 'Chef needs a short pause',
        description:
            'This family has reached a temporary request limit. Wait a moment and try again.',
    },
    500: {
        title: 'Chef hit an unexpected problem',
        description:
            'The issue has been recorded. Return to your workspace and try the last action again.',
    },
    503: {
        title: 'Chef is temporarily unavailable',
        description:
            'Maintenance or a service interruption is in progress. Please try again shortly.',
    },
};

export default function ErrorPage({ status }: { status: number }) {
    const error = errors[status] ?? errors[500];

    return (
        <>
            <Head title={error.title} />
            <main className="flex min-h-screen items-center justify-center bg-background px-5 py-12 text-foreground">
                <div className="w-full max-w-lg text-center">
                    <Link
                        href="/"
                        className="mx-auto mb-10 flex w-fit items-center gap-2.5 font-editorial text-lg font-medium"
                    >
                        <span className="flex size-9 items-center justify-center rounded-lg border bg-white">
                            <AppLogoIcon className="size-7" />
                        </span>
                        Chef
                    </Link>
                    <CircleAlert
                        className="mx-auto size-8 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p className="mt-5 text-sm font-medium text-muted-foreground">
                        Error {status}
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        {error.title}
                    </h1>
                    <p className="mx-auto mt-4 max-w-md leading-7 text-muted-foreground">
                        {error.description}
                    </p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        <Button type="button" onClick={() => router.reload()}>
                            Try again
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            <ArrowLeft /> Go back
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href="/help">Get help</Link>
                        </Button>
                    </div>
                </div>
            </main>
        </>
    );
}
