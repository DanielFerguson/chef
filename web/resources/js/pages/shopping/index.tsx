import { Head, Link } from '@inertiajs/react';
import { ArrowRight, ShoppingBasket } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type ShoppingPlanSummary = {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
    shopping_list: {
        id: number;
        status: 'draft' | 'completed';
        revision: number;
        stale_at: string | null;
        remaining_count: number;
    } | null;
};

const shortDateFormatter = new Intl.DateTimeFormat('en-AU', {
    day: 'numeric',
    month: 'short',
});

function formatDate(value: string) {
    return shortDateFormatter.format(
        new Date(`${value.slice(0, 10)}T00:00:00`),
    );
}

export default function ShoppingIndex({
    plans,
}: {
    plans: ShoppingPlanSummary[];
}) {
    return (
        <>
            <Head title="Shopping" />
            <main className="min-h-0 flex-1 overflow-y-auto bg-background">
                <div className="mx-auto w-full max-w-3xl px-5 py-10 sm:px-8">
                    <div className="flex items-start gap-3">
                        <span className="mt-0.5 rounded-lg bg-primary/10 p-2 text-primary">
                            <ShoppingBasket className="size-5" />
                        </span>
                        <div>
                            <h1 className="text-xl font-semibold">Shopping</h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Turn a confirmed plan into one shared, traceable
                                list.
                            </p>
                        </div>
                    </div>

                    {plans.length === 0 ? (
                        <div className="mt-12 border-t pt-8">
                            <p className="text-sm font-medium">
                                No confirmed plans yet
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Confirm a meal plan before starting its shopping
                                list.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-10 divide-y border-y">
                            {plans.map((plan) => (
                                <div
                                    key={plan.id}
                                    className="flex flex-wrap items-center justify-between gap-4 py-4"
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">
                                                {plan.title}
                                            </p>
                                            {plan.shopping_list && (
                                                <Badge
                                                    variant="secondary"
                                                    className="font-normal"
                                                >
                                                    {plan.shopping_list.stale_at
                                                        ? 'Needs refreshing'
                                                        : plan.shopping_list
                                                                .status ===
                                                            'completed'
                                                          ? 'Completed'
                                                          : `${plan.shopping_list.remaining_count} remaining`}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {formatDate(plan.starts_on)} –{' '}
                                            {formatDate(plan.ends_on)}
                                        </p>
                                    </div>
                                    {plan.shopping_list ? (
                                        <Button asChild size="sm">
                                            <Link
                                                href={`/meal-plans/${plan.id}/shopping`}
                                            >
                                                {plan.shopping_list.stale_at
                                                    ? 'Review changes'
                                                    : plan.shopping_list
                                                            .status ===
                                                        'completed'
                                                      ? 'Review list'
                                                      : 'Continue shopping'}{' '}
                                                <ArrowRight />
                                            </Link>
                                        </Button>
                                    ) : (
                                        <Button asChild size="sm">
                                            <Link
                                                href={`/meal-plans/${plan.id}/shopping-list`}
                                                method="post"
                                                as="button"
                                            >
                                                Start list <ArrowRight />
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </main>
        </>
    );
}
