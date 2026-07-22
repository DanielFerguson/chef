import { useForm } from '@inertiajs/react';
import { confirm } from '@/actions/App/Http/Controllers/RetailerOrderRunController';
import { Button } from '@/components/ui/button';
import type { RetailerOrderRun } from '@/features/shopping/types';

function fulfilmentLabel(type: string | null) {
    if (type === 'pickup') {
        return 'Pickup';
    }

    if (type === 'delivery') {
        return 'Delivery';
    }

    return 'Fulfilment';
}

function selectedSlotLabel(run: RetailerOrderRun) {
    const slot = run.confirmation?.selected_slot ?? run.selected_slot;

    if (!slot || typeof slot !== 'object') {
        return 'Selected time';
    }

    const label = 'label' in slot ? slot.label : undefined;

    if (typeof label === 'string' && label !== '') {
        return label;
    }

    const id = 'id' in slot ? slot.id : undefined;

    return typeof id === 'string' ? id : 'Selected time';
}

export function ConfirmOrderPanel({ run }: { run: RetailerOrderRun }) {
    const form = useForm({});
    const fulfilmentType =
        run.confirmation?.fulfilment_type ?? run.fulfilment_type;
    const consequence =
        run.confirmation?.consequence ??
        'Chef will place the Woolworths order using the default card on file. You can cancel before confirming.';
    const formError = Object.values(form.errors).find(
        (error): error is string => typeof error === 'string' && error !== '',
    );

    return (
        <section
            className="mt-5 rounded-xl border bg-background p-4"
            aria-labelledby="confirm-order-heading"
        >
            <h2 id="confirm-order-heading" className="text-sm font-medium">
                Confirm Woolworths order
            </h2>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Review the fulfilment choice, then confirm in Chef. Chef only
                places the order after this step.
            </p>

            <dl className="mt-4 space-y-2 text-sm">
                <div className="flex flex-wrap gap-x-2 gap-y-1">
                    <dt className="text-muted-foreground">Type</dt>
                    <dd className="font-medium">
                        {fulfilmentLabel(fulfilmentType)}
                    </dd>
                </div>
                <div className="flex flex-wrap gap-x-2 gap-y-1">
                    <dt className="text-muted-foreground">Slot</dt>
                    <dd className="font-medium">{selectedSlotLabel(run)}</dd>
                </div>
            </dl>

            <p className="mt-4 text-sm leading-5">{consequence}</p>

            {formError && (
                <p className="mt-3 text-xs text-destructive">{formError}</p>
            )}

            <Button
                className="mt-4"
                size="sm"
                disabled={form.processing}
                onClick={() =>
                    form.post(confirm.url(run.id), {
                        preserveScroll: true,
                    })
                }
            >
                Confirm and place order
            </Button>
        </section>
    );
}
