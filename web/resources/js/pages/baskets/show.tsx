import { Head, Link, router, useHttp } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    ChevronDown,
    ChevronUp,
    ExternalLink,
    LoaderCircle,
    RefreshCw,
    RotateCcw,
    ShieldCheck,
    ShoppingBasket,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    budgetOverride,
    reviewSession,
    restore,
    retry,
} from '@/routes/basket-runs';
import { update as saveProductPreference } from '@/routes/basket-runs/items/product-preference';
import { show as showMealPlan } from '@/routes/meal-plans';
import { destroy as releaseLiveSession } from '@/routes/retailer-connections/live-session';

type BasketStatus =
    | 'waiting_for_recipes'
    | 'waiting_for_connection'
    | 'building_requirements'
    | 'discovering_products'
    | 'selecting_products'
    | 'preparing_resolution'
    | 'needs_plan_review'
    | 'revalidating_products'
    | 'products_selected'
    | 'replacing_basket'
    | 'ready'
    | 'needs_product'
    | 'reauthentication_required'
    | 'failed'
    | 'uncertain'
    | 'restoring'
    | 'restored'
    | 'needs_attention'
    | 'cancelled';

type BasketPublicState =
    | 'preparing'
    | 'connection_required'
    | 'plan_review_required'
    | 'ready'
    | 'needs_attention'
    | 'failed';

type BasketView = {
    id: number;
    status: BasketStatus;
    status_label: string;
    public_state: BasketPublicState;
    public_outcome:
        | 'basket_ready'
        | 'products_selected'
        | 'basket_restored'
        | 'cancelled'
        | null;
    confirmed: boolean;
    uncertain: boolean;
    failure_code: string | null;
    failure_message: string | null;
    attention: {
        kind: 'budget_overrun' | 'product_unavailable' | string;
        budget_target_cents: number | null;
        selected_subtotal_cents: number | null;
        blocked_requirement_count: number | null;
    } | null;
    plan: {
        id: number;
        title: string;
        starts_on: string;
        ends_on: string;
    };
    connection: {
        id: number;
        provider: 'coles';
        status: string;
        owned_by_current_user: boolean;
        last_verified_at: string | null;
    } | null;
    grocery_plan: {
        id: number;
        version: number;
        status: string;
        built_at: string | null;
    } | null;
    effective_policy: {
        provider: string;
        home_brand_preference: 'allow' | 'prefer' | 'avoid';
        bulk_preference: 'allow' | 'avoid';
        organic_preference: 'no_preference' | 'prefer';
        preferred_brands: string[];
        basket_target_cents: number | null;
        fingerprint: string | null;
    };
    adjustment: {
        id: number;
        kind: 'budget_overrun' | 'product_unavailable';
        status: string;
        generated_at: string;
        items: {
            id: number;
            meal_slot_id: number;
            date: string | null;
            kind: string | null;
            previous_title: string | null;
            replacement_title: string;
            replacement_summary: string;
            estimated_minutes: number | null;
            estimated_cost_cents: number | null;
            proposal_id: number | null;
            proposal_status: string | null;
        }[];
    } | null;
    requirements: {
        id: number;
        name: string;
        status: string;
        quantity: number | null;
        unit: string | null;
        quantity_unknown: boolean;
    }[];
    items: {
        id: number;
        requirement: {
            id: number;
            name: string;
            quantity: number | null;
            unit: string | null;
            quantity_unknown: boolean;
        };
        product: {
            sku: string;
            title: string;
            brand: string | null;
            pack_quantity: number;
            pack_unit: string;
            absolute_quantity: number;
            unit_price_cents: number;
            line_price_cents: number;
        };
        reasoning: string;
        low_confidence: boolean;
        semantic_tier: number | null;
        policy_decisions: string[];
        policy_exceptions: string[];
        alternatives: {
            candidate_id: number;
            sku: string;
            title: string;
            brand: string | null;
            pack_quantity: number;
            pack_unit: string;
            pack_count: number;
            captured_price_cents: number;
            total_price_cents: number;
            captured_at: string;
            policy_comparison: string;
        }[];
        can_prefer: boolean;
        verified_at: string | null;
        sources: {
            planned_meal_id: number;
            meal_title: string;
            recipe_version_id: number;
            recipe_title: string;
            recipe_version: number;
            ingredient_name: string;
            scaled_quantity: number | null;
            unit: string | null;
        }[];
    }[];
    totals: {
        chef_subtotal_cents: number | null;
        retailer_total_cents: number | null;
        price_notice: string;
    };
    replaced_line_count: number;
    previous_line_count: number;
    captured_at: string | null;
    can: {
        retry: boolean;
        restore: boolean;
        review: boolean;
        override_budget: boolean;
    };
};

type ReviewSessionResponse = {
    session: {
        live_view_url: string;
        expires_at: string;
    };
};

const phases = [
    ['waiting_for_recipes', 'Recipes'],
    ['building_requirements', 'Requirements'],
    ['discovering_products', 'Products'],
    ['replacing_basket', 'Replacement'],
    ['ready', 'Verified'],
] as const;

function money(value: number | null) {
    return value === null
        ? 'Not available'
        : new Intl.NumberFormat('en-AU', {
              style: 'currency',
              currency: 'AUD',
          }).format(value / 100);
}

function dateTime(value: string | null) {
    return value === null
        ? 'Not captured'
        : new Intl.DateTimeFormat('en-AU', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value));
}

function phaseIndex(status: BasketStatus) {
    if (status === 'selecting_products' || status === 'revalidating_products') {
        return 2;
    }

    if (status === 'uncertain' || status === 'failed') {
        return 3;
    }

    if (status === 'preparing_resolution' || status === 'needs_plan_review') {
        return 2;
    }

    return phases.findIndex(([phase]) => phase === status);
}

function BasketAlternative({
    basketRunId,
    basketItemId,
    alternative,
    canPrefer,
}: {
    basketRunId: number;
    basketItemId: number;
    alternative: BasketView['items'][number]['alternatives'][number];
    canPrefer: boolean;
}) {
    const request = useHttp<
        { retailer_product_candidate_id: number },
        { preference: { id: number } }
    >({ retailer_product_candidate_id: alternative.candidate_id });
    const [saved, setSaved] = useState(false);

    return (
        <li className="flex flex-col gap-3 border-t py-3 first:border-0 first:pt-0 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p className="font-medium">{alternative.title}</p>
                <p className="text-muted-foreground">
                    {alternative.pack_count} × {alternative.pack_quantity}
                    {alternative.pack_unit} ·{' '}
                    {money(alternative.total_price_cents)}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {alternative.policy_comparison}
                </p>
            </div>
            {canPrefer && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={request.processing || saved}
                    onClick={async () => {
                        await request.put(
                            saveProductPreference.url({
                                basketRun: basketRunId,
                                basketRunItem: basketItemId,
                            }),
                        );
                        setSaved(true);
                    }}
                >
                    {saved ? 'Saved for next time' : 'Prefer next time'}
                </Button>
            )}
        </li>
    );
}

function BasketItem({
    basketRunId,
    item,
}: {
    basketRunId: number;
    item: BasketView['items'][number];
}) {
    const [expanded, setExpanded] = useState(false);

    return (
        <li className="py-5 first:pt-0 last:pb-0">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-medium">{item.product.title}</h3>
                        {item.low_confidence && (
                            <Badge variant="outline">Best valid guess</Badge>
                        )}
                        {item.verified_at !== null && (
                            <Badge variant="secondary">
                                <Check /> Verified
                            </Badge>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {item.product.absolute_quantity} ×{' '}
                        {item.product.pack_quantity}
                        {item.product.pack_unit} for {item.requirement.name}
                    </p>
                </div>
                <p className="shrink-0 font-medium tabular-nums">
                    {money(item.product.line_price_cents)}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                className="mt-2 -ml-3"
                aria-expanded={expanded}
                onClick={() => setExpanded((current) => !current)}
            >
                {expanded ? <ChevronUp /> : <ChevronDown />}
                Why this pack
            </Button>
            {expanded && (
                <div className="mt-2 space-y-3 rounded-lg bg-muted/40 p-4 text-sm leading-6">
                    <p>{item.reasoning}</p>
                    <div>
                        <p className="font-medium">Recipe sources</p>
                        <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                            {item.sources.map((source) => (
                                <li
                                    key={`${source.planned_meal_id}-${source.recipe_version_id}-${source.ingredient_name}`}
                                >
                                    {source.meal_title}:{' '}
                                    {source.ingredient_name}
                                    {source.scaled_quantity === null
                                        ? ''
                                        : ` · ${source.scaled_quantity}${source.unit ? ` ${source.unit}` : ''}`}
                                </li>
                            ))}
                        </ul>
                    </div>
                    {item.policy_exceptions.length > 0 && (
                        <div>
                            <p className="font-medium">Policy exceptions</p>
                            <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                                {item.policy_exceptions.map((exception) => (
                                    <li key={exception}>{exception}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {item.alternatives.length > 0 && (
                        <div>
                            <p className="font-medium">Other valid options</p>
                            <ul className="mt-2">
                                {item.alternatives.map((alternative) => (
                                    <BasketAlternative
                                        key={alternative.candidate_id}
                                        basketRunId={basketRunId}
                                        basketItemId={item.id}
                                        alternative={alternative}
                                        canPrefer={item.can_prefer}
                                    />
                                ))}
                            </ul>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Saving a preference does not change this Coles
                                basket.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

export default function BasketShow({ basket }: { basket: BasketView }) {
    const active = basket.public_state === 'preparing';
    const currentPhase = phaseIndex(basket.status);
    const [restoreOpen, setRestoreOpen] = useState(false);
    const [reviewOpen, setReviewOpen] = useState(false);
    const reviewRequest = useHttp<Record<string, never>, ReviewSessionResponse>(
        {},
    );
    const releaseRequest = useHttp<Record<string, never>, { released: true }>(
        {},
    );
    const budgetRequest = useHttp<
        Record<string, never>,
        { basket_run: { id: number; status: BasketStatus } }
    >({});
    const releaseSession = releaseRequest.delete;
    const reviewOpenRef = useRef(false);
    const [reviewSessionUrl, setReviewSessionUrl] = useState<string | null>(
        null,
    );
    const policyExceptions = Array.from(
        new Set(basket.items.flatMap((item) => item.policy_exceptions)),
    );

    useEffect(() => {
        if (!active) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['basket'] });
            }
        }, 2500);

        return () => window.clearInterval(interval);
    }, [active]);

    const openReview = async () => {
        reviewOpenRef.current = true;
        setReviewOpen(true);
        const response = await reviewRequest.post(reviewSession.url(basket.id));

        if (!reviewOpenRef.current) {
            if (basket.connection !== null) {
                await releaseSession(
                    releaseLiveSession.url(basket.connection.id),
                );
            }

            return;
        }

        setReviewSessionUrl(response.session.live_view_url);
    };

    const closeReview = async () => {
        reviewOpenRef.current = false;

        if (basket.connection !== null && reviewSessionUrl !== null) {
            await releaseSession(releaseLiveSession.url(basket.connection.id));
        }

        setReviewOpen(false);
        setReviewSessionUrl(null);
    };

    const acceptBudgetException = async () => {
        await budgetRequest.post(budgetOverride.url(basket.id));
        router.reload({ only: ['basket'] });
    };

    return (
        <>
            <Head title={`Coles basket · ${basket.plan.title}`} />
            <main className="min-w-0 flex-1 overflow-y-auto bg-background">
                <div className="mx-auto w-full max-w-4xl px-5 py-8 sm:px-8 lg:py-12">
                    <Button asChild variant="ghost" className="-ml-3">
                        <Link href={showMealPlan(basket.plan.id)}>
                            <ArrowLeft /> Back to meal plan
                        </Link>
                    </Button>

                    <header className="mt-6 border-b pb-8">
                        <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-sm font-medium text-primary">
                                    Coles basket
                                </p>
                                <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                                    {basket.plan.title}
                                </h1>
                                <div
                                    className="mt-3 flex items-center gap-2 text-sm"
                                    aria-live="polite"
                                >
                                    {active ? (
                                        <LoaderCircle className="size-4 animate-spin" />
                                    ) : basket.confirmed ? (
                                        <ShieldCheck className="size-4 text-emerald-700 dark:text-emerald-400" />
                                    ) : basket.uncertain ? (
                                        <AlertTriangle className="size-4 text-amber-700 dark:text-amber-400" />
                                    ) : (
                                        <ShoppingBasket className="size-4" />
                                    )}
                                    <span className="font-medium">
                                        {basket.status_label}
                                    </span>
                                    {basket.confirmed && (
                                        <span className="text-muted-foreground">
                                            · confirmed from the actual Coles
                                            basket
                                        </span>
                                    )}
                                    {basket.uncertain && (
                                        <span className="text-muted-foreground">
                                            · not confirmed
                                        </span>
                                    )}
                                </div>
                                {basket.confirmed && (
                                    <div className="mt-5">
                                        <p className="text-2xl font-semibold tracking-tight">
                                            {basket.items.length}{' '}
                                            {basket.items.length === 1
                                                ? 'product'
                                                : 'products'}{' '}
                                            ·{' '}
                                            {money(
                                                basket.totals
                                                    .retailer_total_cents ??
                                                    basket.totals
                                                        .chef_subtotal_cents,
                                            )}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Verified in Coles{' '}
                                            {dateTime(basket.captured_at)}
                                        </p>
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {basket.can.retry && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(retry.url(basket.id))
                                        }
                                    >
                                        <RefreshCw /> Retry
                                    </Button>
                                )}
                                {basket.can.restore && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setRestoreOpen(true)}
                                    >
                                        <RotateCcw /> Restore previous basket
                                    </Button>
                                )}
                                {basket.can.review && (
                                    <Button onClick={() => void openReview()}>
                                        Review in Coles <ExternalLink />
                                    </Button>
                                )}
                            </div>
                        </div>

                        {policyExceptions.length > 0 && basket.confirmed && (
                            <div className="mt-5 rounded-xl border p-4 text-sm leading-6">
                                <p className="font-medium">
                                    {policyExceptions.length}{' '}
                                    {policyExceptions.length === 1
                                        ? 'policy exception'
                                        : 'policy exceptions'}
                                </p>
                                <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                                    {policyExceptions.map((exception) => (
                                        <li key={exception}>{exception}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {basket.failure_message !== null &&
                            basket.public_state !== 'plan_review_required' && (
                                <div className="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm leading-6">
                                    <p className="font-medium">
                                        Chef stopped automation
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        {basket.failure_message}
                                    </p>
                                </div>
                            )}
                    </header>

                    {active && (
                        <section
                            className="mt-6 rounded-xl border bg-muted/30 p-5"
                            aria-live="polite"
                        >
                            <div className="flex items-start gap-3">
                                <LoaderCircle className="mt-0.5 size-5 shrink-0 animate-spin" />
                                <div>
                                    <h2 className="font-medium">
                                        Preparing your Coles basket
                                    </h2>
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        You can leave this page. Chef will
                                        notify you when the basket is ready or
                                        needs a decision.
                                    </p>
                                </div>
                            </div>
                        </section>
                    )}

                    {basket.public_state === 'connection_required' && (
                        <section className="mt-6 rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                            <h2 className="font-medium">Continue with Coles</h2>
                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                The Coles account owner needs to reconnect. Chef
                                will resume this preparation after
                                authentication succeeds.
                            </p>
                            <Button asChild className="mt-4">
                                <Link href={showMealPlan(basket.plan.id)}>
                                    Continue with Coles
                                </Link>
                            </Button>
                        </section>
                    )}

                    {basket.public_state === 'plan_review_required' &&
                        basket.adjustment !== null && (
                            <section className="mt-6 rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                                <h2 className="font-medium">
                                    {basket.attention?.kind === 'budget_overrun'
                                        ? 'This basket is over your target'
                                        : 'Chef found a coherent plan alternative'}
                                </h2>
                                {basket.attention?.kind ===
                                    'budget_overrun' && (
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        The selected products total{' '}
                                        {money(
                                            basket.attention
                                                .selected_subtotal_cents,
                                        )}{' '}
                                        against a target of{' '}
                                        {money(
                                            basket.attention
                                                .budget_target_cents,
                                        )}
                                        . Chef has drafted one cheaper plan for
                                        you to review.
                                    </p>
                                )}
                                {basket.attention?.kind ===
                                    'product_unavailable' && (
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        No safe product passed every check for{' '}
                                        {basket.attention
                                            .blocked_requirement_count ??
                                            'one or more'}{' '}
                                        requirements. Your existing Coles basket
                                        is unchanged.
                                    </p>
                                )}
                                <div className="mt-4 divide-y rounded-lg border bg-background px-4">
                                    {basket.adjustment.items.map((item) => (
                                        <div key={item.id} className="py-4">
                                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                {[item.date, item.kind]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {item.previous_title ??
                                                    'Current meal'}{' '}
                                                → {item.replacement_title}
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                                {item.replacement_summary}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {basket.attention?.kind ===
                                        'budget_overrun' &&
                                        basket.can.override_budget && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    budgetRequest.processing
                                                }
                                                onClick={() =>
                                                    void acceptBudgetException()
                                                }
                                            >
                                                {budgetRequest.processing
                                                    ? 'Checking products…'
                                                    : 'Use this basket'}
                                            </Button>
                                        )}
                                    <Button asChild>
                                        <Link
                                            href={showMealPlan(basket.plan.id)}
                                        >
                                            {basket.attention?.kind ===
                                            'budget_overrun'
                                                ? 'Review cheaper plan'
                                                : 'Review revised plan'}
                                        </Link>
                                    </Button>
                                </div>
                                {basket.attention?.kind === 'budget_overrun' &&
                                    !basket.can.override_budget && (
                                        <p className="mt-3 text-xs text-muted-foreground">
                                            Only the connected Coles account
                                            owner can prepare the over-target
                                            basket.
                                        </p>
                                    )}
                                {Object.values(budgetRequest.errors)[0] !==
                                    undefined && (
                                    <p
                                        role="alert"
                                        className="mt-3 text-sm text-destructive"
                                    >
                                        {String(
                                            Object.values(
                                                budgetRequest.errors,
                                            )[0],
                                        )}
                                    </p>
                                )}
                            </section>
                        )}

                    <details className="border-b py-5">
                        <summary className="cursor-pointer text-sm font-medium">
                            Preparation details
                        </summary>
                        <ol
                            className="mt-5 grid grid-cols-5 gap-2 text-center text-[11px] text-muted-foreground sm:text-xs"
                            aria-label="Basket preparation progress"
                        >
                            {phases.map(([, label], index) => (
                                <li
                                    key={label}
                                    className={
                                        index <= currentPhase
                                            ? 'font-medium text-foreground'
                                            : undefined
                                    }
                                >
                                    <span
                                        className={`mx-auto mb-2 block size-2 rounded-full ${
                                            index <= currentPhase
                                                ? 'bg-primary'
                                                : 'bg-muted-foreground/30'
                                        }`}
                                    />
                                    {label}
                                </li>
                            ))}
                        </ol>
                        <p className="mt-4 text-xs text-muted-foreground">
                            Current internal phase: {basket.status_label}. Chef
                            does not estimate completion until pilot data makes
                            that reliable.
                        </p>
                    </details>

                    <div className="grid gap-10 py-8 lg:grid-cols-[minmax(0,1fr)_17rem]">
                        <section>
                            <div className="flex items-center justify-between">
                                <h2 className="text-xl font-semibold">
                                    Selected products
                                </h2>
                                <span className="text-sm text-muted-foreground">
                                    {basket.items.length}{' '}
                                    {basket.items.length === 1
                                        ? 'line'
                                        : 'lines'}
                                </span>
                            </div>
                            {basket.items.length > 0 ? (
                                <ul className="mt-5 divide-y">
                                    {basket.items.map((item) => (
                                        <BasketItem
                                            key={item.id}
                                            basketRunId={basket.id}
                                            item={item}
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-5 rounded-xl border border-dashed p-6 text-sm leading-6 text-muted-foreground">
                                    {active
                                        ? 'Products will appear after every grocery requirement passes the hard checks.'
                                        : 'No product selection was safe to present for this run.'}
                                </p>
                            )}

                            {basket.requirements.some(
                                (requirement) =>
                                    requirement.status === 'needs_product',
                            ) && (
                                <section className="mt-8 rounded-xl border border-amber-500/30 p-5">
                                    <h2 className="font-medium">
                                        Requirements without a valid product
                                    </h2>
                                    <ul className="mt-3 list-disc pl-5 text-sm text-muted-foreground">
                                        {basket.requirements
                                            .filter(
                                                (requirement) =>
                                                    requirement.status ===
                                                    'needs_product',
                                            )
                                            .map((requirement) => (
                                                <li key={requirement.id}>
                                                    {requirement.name}
                                                </li>
                                            ))}
                                    </ul>
                                </section>
                            )}
                        </section>

                        <aside className="h-fit rounded-xl border p-5">
                            <h2 className="font-medium">Verified totals</h2>
                            <dl className="mt-4 space-y-3 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Selected products
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {money(
                                            basket.totals.chef_subtotal_cents,
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3 border-t pt-3">
                                    <dt>Full Coles basket</dt>
                                    <dd className="font-semibold tabular-nums">
                                        {money(
                                            basket.totals.retailer_total_cents,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-4 text-xs leading-5 text-muted-foreground">
                                {basket.totals.price_notice}
                            </p>
                            <dl className="mt-5 space-y-2 border-t pt-4 text-xs text-muted-foreground">
                                <div className="flex justify-between gap-3">
                                    <dt>Captured</dt>
                                    <dd className="text-right">
                                        {dateTime(basket.captured_at)}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Previous lines</dt>
                                    <dd>{basket.previous_line_count}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt>Replaced lines</dt>
                                    <dd>{basket.replaced_line_count}</dd>
                                </div>
                            </dl>
                        </aside>
                    </div>
                </div>
            </main>

            <Dialog open={restoreOpen} onOpenChange={setRestoreOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Restore the previous basket?</DialogTitle>
                        <DialogDescription>
                            Chef will stop other automation, clear the current
                            trolley, and restore the{' '}
                            {basket.previous_line_count} lines captured before
                            this run.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setRestoreOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                setRestoreOpen(false);
                                router.post(restore.url(basket.id));
                            }}
                        >
                            <RotateCcw /> Restore previous basket
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={reviewOpen}
                onOpenChange={(open) => {
                    if (open) {
                        setReviewOpen(true);
                    } else {
                        void closeReview();
                    }
                }}
            >
                <DialogContent className="max-h-[calc(100svh-2rem)] overflow-y-auto p-0 sm:max-w-4xl">
                    <DialogHeader className="px-5 pt-5 sm:px-6 sm:pt-6">
                        <DialogTitle>Review in Coles</DialogTitle>
                        <DialogDescription>
                            Automation is stopped. This is your private Coles
                            session; checkout, fulfilment, and payment are
                            entirely under your control.
                        </DialogDescription>
                    </DialogHeader>
                    {reviewSessionUrl === null ? (
                        <div className="flex min-h-80 items-center justify-center gap-2 text-sm text-muted-foreground">
                            <LoaderCircle className="size-4 animate-spin" />
                            Opening Coles…
                        </div>
                    ) : (
                        <div className="mx-5 overflow-hidden rounded-xl border sm:mx-6">
                            <iframe
                                src={reviewSessionUrl}
                                title="Review your Coles basket"
                                className="h-[65svh] min-h-96 w-full bg-white"
                                referrerPolicy="no-referrer"
                                allow="clipboard-read; clipboard-write"
                            />
                        </div>
                    )}
                    {Object.values(reviewRequest.errors)[0] !== undefined && (
                        <p
                            role="alert"
                            className="mx-5 text-sm text-destructive sm:mx-6"
                        >
                            {String(Object.values(reviewRequest.errors)[0])}
                        </p>
                    )}
                    <DialogFooter className="border-t px-5 py-4 sm:px-6">
                        <Button
                            variant="outline"
                            onClick={() => void closeReview()}
                        >
                            Close review
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
