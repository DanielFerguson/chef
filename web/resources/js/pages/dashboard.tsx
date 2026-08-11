import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Check,
    ChefHat,
    Clock3,
    Plus,
    ShieldCheck,
    UsersRound,
    X,
} from 'lucide-react';
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

type TodayMeal = {
    id: number;
    title: string;
    type: string;
    date: string;
    kind: string;
    label: string | null;
    participants: { id: number; name: string }[];
    recipe: {
        title: string;
        summary: string | null;
        total_minutes: number;
        preparation_notices: {
            id: number;
            instruction: string;
            lead_minutes: number | null;
        }[];
    } | null;
    outcome: {
        id: number;
        status: string | null;
        started_at: string | null;
        completed_at: string | null;
        feedback_count: number;
    } | null;
};

type PreferenceCandidate = {
    id: number;
    person: { id: number; name: string };
    subject: string;
    sentiment: 'like' | 'dislike';
    evidence_count: number;
    confidence: number;
    status: 'pending';
};

const dayFormatter = new Intl.DateTimeFormat('en-AU', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

function formatDate(value: string) {
    return dayFormatter.format(new Date(`${value.slice(0, 10)}T00:00:00`));
}

function MealCard({ meal }: { meal: TodayMeal }) {
    const completed =
        meal.outcome?.completed_at !== null && meal.outcome !== null;
    const started = meal.outcome?.started_at !== null && meal.outcome !== null;

    return (
        <article
            className="border-t py-6 first:border-t-0 first:pt-0"
            data-today-meal={meal.id}
        >
            <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="capitalize">
                            {meal.label ?? meal.kind}
                        </Badge>
                        {completed && (
                            <Badge variant="secondary">
                                {meal.outcome?.status?.replace('_', ' ')}{' '}
                                recorded
                            </Badge>
                        )}
                    </div>
                    <h2 className="mt-3 text-xl font-semibold tracking-tight">
                        {meal.title}
                    </h2>
                    {meal.recipe?.summary && (
                        <p className="mt-2 max-w-xl text-sm leading-6 text-muted-foreground">
                            {meal.recipe.summary}
                        </p>
                    )}
                    <div className="mt-3 flex flex-wrap gap-4 text-xs text-muted-foreground">
                        {meal.recipe && (
                            <span className="flex items-center gap-1.5">
                                <Clock3 className="size-3.5" />
                                {meal.recipe.total_minutes > 0
                                    ? `${meal.recipe.total_minutes} min`
                                    : 'Time not recorded'}
                            </span>
                        )}
                        <span className="flex items-center gap-1.5">
                            <UsersRound className="size-3.5" />
                            {meal.participants
                                .map((person) => person.name)
                                .join(', ') || 'No participants'}
                        </span>
                    </div>
                    {meal.recipe?.preparation_notices[0] && (
                        <p className="mt-4 max-w-xl border-l-2 border-primary pl-3 text-sm leading-6 text-muted-foreground">
                            {meal.recipe.preparation_notices[0].instruction}
                        </p>
                    )}
                </div>
                <Button asChild size="lg" className="min-h-11 shrink-0">
                    {meal.recipe && !started ? (
                        <Link
                            href={`/planned-meals/${meal.id}/cook`}
                            method="post"
                            as="button"
                        >
                            Start cooking <ArrowRight />
                        </Link>
                    ) : (
                        <Link href={`/planned-meals/${meal.id}/cook`}>
                            {completed
                                ? 'Review meal'
                                : started
                                  ? 'Continue cooking'
                                  : 'Record outcome'}
                            <ArrowRight />
                        </Link>
                    )}
                </Button>
            </div>
        </article>
    );
}

function CandidateRow({ candidate }: { candidate: PreferenceCandidate }) {
    const decide = (decision: 'accepted' | 'dismissed') =>
        router.put(
            `/preference-candidates/${candidate.id}`,
            { decision },
            { preserveScroll: true },
        );

    return (
        <li className="border-t py-4 first:border-t-0 first:pt-0">
            <p className="text-sm leading-6">
                <span className="font-medium">{candidate.person.name}</span>{' '}
                {candidate.sentiment === 'like' ? 'may like' : 'may dislike'}{' '}
                <span className="font-medium">{candidate.subject}</span>
            </p>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Based on {candidate.evidence_count} meal ratings ·{' '}
                {Math.round(candidate.confidence * 100)}% confidence · candidate
                only
            </p>
            <div className="mt-3 flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => decide('accepted')}
                >
                    <Check /> Keep preference
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    onClick={() => decide('dismissed')}
                >
                    <X />
                    <span className="sr-only">
                        Dismiss preference candidate
                    </span>
                </Button>
            </div>
        </li>
    );
}

export default function Dashboard({
    household,
    today,
    preferenceCandidates,
}: {
    household: Household;
    today: {
        date: string;
        showing_next: boolean;
        meals: TodayMeal[];
    };
    preferenceCandidates: PreferenceCandidate[];
}) {
    const { auth } = usePage().props;
    const hasMeals = today.meals.length > 0;

    return (
        <>
            <Head title="Today" />
            <div className="flex min-h-0 flex-1 flex-col bg-background lg:flex-row">
                <main className="min-w-0 flex-1 overflow-y-auto">
                    <div className="mx-auto w-full max-w-3xl px-5 py-8 sm:px-8 lg:py-12">
                        {hasMeals ? (
                            <>
                                <header className="border-b pb-7">
                                    <p className="text-sm font-medium text-primary">
                                        {today.showing_next
                                            ? 'Next up'
                                            : 'Today'}
                                    </p>
                                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                                        {formatDate(
                                            today.meals[0]?.date ?? today.date,
                                        )}
                                    </h1>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {today.showing_next
                                            ? 'Nothing is planned for today. Here is the next meal on the family plan.'
                                            : 'Everything you need to make today’s meals, without finding the planning conversation.'}
                                    </p>
                                </header>
                                <section
                                    className="py-7"
                                    aria-label="Meals for today"
                                >
                                    {today.meals.map((meal) => (
                                        <MealCard key={meal.id} meal={meal} />
                                    ))}
                                </section>
                            </>
                        ) : (
                            <div className="flex min-h-[calc(100vh-12rem)] flex-col items-center justify-center py-12 text-center">
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
                                    Welcome to Chef,{' '}
                                    {auth.user.name.split(' ')[0]}
                                </h1>
                                <p className="mt-3 max-w-lg text-sm leading-6 text-pretty text-muted-foreground sm:text-base">
                                    Start with an ordinary conversation. Chef
                                    will turn the people, dates, preferences,
                                    and meals you describe into a shared plan
                                    you can cook from.
                                </p>
                                <div className="mt-8 grid w-full max-w-lg gap-3 text-left sm:grid-cols-2">
                                    <div className="rounded-xl border bg-card p-4">
                                        <CalendarDays className="mb-3 size-4 text-primary" />
                                        <p className="text-sm font-medium">
                                            Plan naturally
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            Describe the days, people, and food
                                            you feel like.
                                        </p>
                                    </div>
                                    <div className="rounded-xl border bg-card p-4">
                                        <UsersRound className="mb-3 size-4 text-primary" />
                                        <p className="text-sm font-medium">
                                            Plan together
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            Everyone sees the same durable
                                            family workspace.
                                        </p>
                                    </div>
                                </div>
                                <Button asChild className="mt-8">
                                    <Link
                                        href="/meal-plans"
                                        method="post"
                                        as="button"
                                    >
                                        <Plus /> Start a plan
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>
                </main>

                <aside className="border-t bg-muted/20 p-5 lg:w-80 lg:overflow-y-auto lg:border-t-0 lg:border-l xl:w-96">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium">
                                {household.name}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {household.timezone.replace('_', ' ')}
                            </p>
                        </div>
                        <ChefHat className="size-5 text-muted-foreground" />
                    </div>

                    {preferenceCandidates.length > 0 && (
                        <section
                            className="mt-8"
                            aria-labelledby="preference-candidates-title"
                        >
                            <div className="flex items-center gap-2">
                                <ShieldCheck className="size-4 text-primary" />
                                <h2
                                    id="preference-candidates-title"
                                    className="text-sm font-semibold"
                                >
                                    Patterns to review
                                </h2>
                            </div>
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                These are suggestions from repeated meal
                                feedback. They are not safety rules and do not
                                become preferences without review.
                            </p>
                            <ul className="mt-4">
                                {preferenceCandidates.map((candidate) => (
                                    <CandidateRow
                                        key={candidate.id}
                                        candidate={candidate}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}

                    <section className="mt-8">
                        <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Family
                        </h2>
                        <div className="mt-3 space-y-3">
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
                    </section>
                </aside>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Today', href: dashboard() }],
};
