import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays, Plus, UsersRound } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type HouseholdPerson = {
    id: number;
    name: string;
    has_account: boolean;
    email: string | null;
};

type Household = {
    id: number;
    name: string;
    timezone: string;
    people: HouseholdPerson[];
};

export default function Dashboard({ household }: { household: Household }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Today" />
            <div className="flex min-h-0 flex-1 flex-col bg-background lg:flex-row">
                <main className="flex min-h-[calc(100vh-4rem)] min-w-0 flex-1 flex-col lg:min-h-0">
                    <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-5 py-8 sm:px-8 lg:py-12">
                        <div className="flex flex-1 flex-col items-center justify-center py-12 text-center">
                            <span className="mb-6 flex size-14 items-center justify-center rounded-2xl border bg-white">
                                <AppLogoIcon className="size-10" />
                            </span>
                            <Badge
                                variant="secondary"
                                className="mb-4 font-normal"
                            >
                                Family workspace ready
                            </Badge>
                            <h1 className="max-w-xl text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                Welcome to Chef, {auth.user.name.split(' ')[0]}
                            </h1>
                            <p className="mt-3 max-w-lg text-sm leading-6 text-pretty text-muted-foreground sm:text-base">
                                Start with an ordinary conversation. Chef will
                                turn the people, dates, preferences, and meals
                                you describe into a shared plan you can edit.
                            </p>
                            <div className="mt-8 grid w-full max-w-lg gap-3 text-left sm:grid-cols-2">
                                <div className="rounded-xl border bg-card p-4">
                                    <CalendarDays className="mb-3 size-4 text-primary" />
                                    <p className="text-sm font-medium">
                                        Plan naturally
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        Describe the days, people, budget, and
                                        food you feel like.
                                    </p>
                                </div>
                                <div className="rounded-xl border bg-card p-4">
                                    <UsersRound className="mb-3 size-4 text-primary" />
                                    <p className="text-sm font-medium">
                                        Plan together
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        Everyone sees the same durable family
                                        workspace.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-2xl border bg-card p-4 shadow-sm">
                            <p className="text-sm text-muted-foreground">
                                Chef will begin with the next seven days. You
                                can change the date span in the conversation.
                            </p>
                            <Button asChild className="shrink-0">
                                <Link
                                    href="/meal-plans"
                                    method="post"
                                    as="button"
                                >
                                    <Plus /> Start a plan
                                </Link>
                            </Button>
                        </div>
                    </div>
                </main>

                <aside className="border-t bg-muted/20 p-5 lg:w-72 lg:border-t-0 lg:border-l xl:w-80">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium">
                                {household.name}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {household.timezone.replace('_', ' ')}
                            </p>
                        </div>
                        <Button variant="outline" size="icon" disabled>
                            <Plus />
                            <span className="sr-only">Invite person</span>
                        </Button>
                    </div>

                    <div className="mt-7">
                        <p className="mb-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            People
                        </p>
                        <div className="space-y-3">
                            {household.people.map((person) => (
                                <div
                                    key={person.id}
                                    className="flex items-center gap-3"
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full border bg-background text-xs font-medium">
                                        {person.name.slice(0, 1).toUpperCase()}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm">
                                            {person.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {person.has_account
                                                ? person.email
                                                : 'No account needed'}
                                        </span>
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="mt-8 rounded-xl border border-dashed bg-background/60 p-4">
                        <p className="text-xs font-medium">
                            No active meal plan
                        </p>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            Your first plan will appear here as you talk with
                            Chef.
                        </p>
                    </div>
                </aside>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Today',
            href: dashboard(),
        },
    ],
};
