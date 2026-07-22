import { router } from '@inertiajs/react';
import { useState } from 'react';
import { resolveCartDecision } from '@/actions/App/Http/Controllers/RetailerOrderRunController';
import { Button } from '@/components/ui/button';
import type { RetailerOrderRun } from '@/features/shopping/types';

export function CartDecisionPanel({ run }: { run: RetailerOrderRun }) {
    const [processing, setProcessing] = useState(false);

    const choose = (choice: 'merge' | 'replace' | 'cancel') => {
        setProcessing(true);
        router.put(
            resolveCartDecision.url(run.id),
            { choice },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <section
            className="mt-5 rounded-xl border bg-background p-4"
            aria-labelledby="cart-decision-heading"
        >
            <h2 id="cart-decision-heading" className="text-sm font-medium">
                Woolworths already has a cart
            </h2>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Choose whether Chef should merge with those items, replace the
                cart, or cancel this order run.
            </p>
            <div className="mt-4 flex flex-wrap gap-2">
                <Button
                    size="sm"
                    disabled={processing}
                    onClick={() => choose('merge')}
                >
                    Merge carts
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => choose('replace')}
                >
                    Replace existing cart
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    onClick={() => choose('cancel')}
                >
                    Cancel order run
                </Button>
            </div>
        </section>
    );
}
