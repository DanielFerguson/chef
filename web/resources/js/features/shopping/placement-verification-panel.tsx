import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { verify } from '@/actions/App/Http/Controllers/RetailerOrderRunController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { RetailerOrderRun } from '@/features/shopping/types';

export function PlacementVerificationPanel({
    run,
}: {
    run: RetailerOrderRun;
}) {
    const form = useForm({
        retailer_order_reference: '',
        acknowledged_placed: false as boolean,
    });
    const formError =
        form.errors.retailer_order_reference ??
        form.errors.acknowledged_placed ??
        Object.values(form.errors)[0];
    const canSubmit =
        form.data.retailer_order_reference.trim() !== '' ||
        form.data.acknowledged_placed;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            retailer_order_reference:
                data.retailer_order_reference.trim() || null,
            acknowledged_placed: data.acknowledged_placed,
        }));
        form.post(verify.url(run.id), {
            preserveScroll: true,
        });
    };

    return (
        <section
            className="mt-5 rounded-xl border bg-background p-4"
            aria-labelledby="placement-verification-heading"
        >
            <h2
                id="placement-verification-heading"
                className="text-sm font-medium"
            >
                Verify Woolworths placement
            </h2>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Chef could not read a clear order number. Enter it from
                Woolworths, or confirm that the order was placed.
            </p>

            {run.open_woolworths_cart_url && (
                <p className="mt-3 text-xs">
                    <a
                        className="underline underline-offset-2"
                        href={run.open_woolworths_cart_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open Woolworths
                    </a>{' '}
                    to check the confirmation.
                </p>
            )}

            <form className="mt-4 space-y-4" onSubmit={submit}>
                <div>
                    <label
                        className="text-xs font-medium text-muted-foreground"
                        htmlFor={`retailer-order-reference-${run.id}`}
                    >
                        Woolworths order number
                    </label>
                    <Input
                        id={`retailer-order-reference-${run.id}`}
                        className="mt-1.5"
                        value={form.data.retailer_order_reference}
                        disabled={form.processing}
                        autoComplete="off"
                        onChange={(event) =>
                            form.setData(
                                'retailer_order_reference',
                                event.target.value,
                            )
                        }
                    />
                </div>

                <label className="flex items-start gap-2 text-sm leading-5">
                    <Checkbox
                        className="mt-0.5"
                        checked={form.data.acknowledged_placed}
                        disabled={form.processing}
                        onCheckedChange={(checked) =>
                            form.setData(
                                'acknowledged_placed',
                                checked === true,
                            )
                        }
                        aria-label="Confirm the Woolworths order was placed"
                    />
                    <span>
                        I confirm the Woolworths order was placed, even without
                        an order number.
                    </span>
                </label>

                {formError && (
                    <p className="text-xs text-destructive">{formError}</p>
                )}

                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || !canSubmit}
                >
                    Save verification
                </Button>
            </form>
        </section>
    );
}
