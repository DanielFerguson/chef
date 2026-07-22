import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { selectFulfilment } from '@/actions/App/Http/Controllers/RetailerOrderRunController';
import { Button } from '@/components/ui/button';
import type { RetailerOrderRun } from '@/features/shopping/types';

type FulfilmentType = 'delivery' | 'pickup';

function slotLabel(slot: {
    id: string;
    label?: string;
    starts_at?: string | null;
    ends_at?: string | null;
    fee?: number | null;
}) {
    if (slot.label) {
        return slot.label;
    }

    const start = slot.starts_at
        ? new Date(slot.starts_at).toLocaleString('en-AU', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: 'numeric',
              minute: '2-digit',
          })
        : null;
    const end = slot.ends_at
        ? new Date(slot.ends_at).toLocaleTimeString('en-AU', {
              hour: 'numeric',
              minute: '2-digit',
          })
        : null;

    if (start && end) {
        return `${start} – ${end}`;
    }

    return start ?? slot.id;
}

export function FulfilmentSlotPicker({ run }: { run: RetailerOrderRun }) {
    const optionsType =
        run.fulfilment_options?.type ?? run.fulfilment_type ?? 'delivery';
    const slots = run.fulfilment_options?.slots ?? [];
    const form = useForm<{
        slot_id: string;
        fulfilment_type: FulfilmentType;
    }>({
        slot_id: slots[0]?.id ?? '',
        fulfilment_type: optionsType,
    });
    const formError =
        form.errors.slot_id ??
        form.errors.fulfilment_type ??
        Object.values(form.errors)[0];

    const chooseType = (type: FulfilmentType) => {
        if (run.fulfilment_options?.type && run.fulfilment_options.type !== type) {
            return;
        }

        form.setData('fulfilment_type', type);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!form.data.slot_id) {
            return;
        }

        form.post(selectFulfilment.url(run.id), {
            preserveScroll: true,
        });
    };

    return (
        <section
            className="mt-5 rounded-xl border bg-background p-4"
            aria-labelledby="fulfilment-slot-heading"
        >
            <h2 id="fulfilment-slot-heading" className="text-sm font-medium">
                Choose a {optionsType} time
            </h2>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Pick how and when you want this shop. Chef waits for your
                choice before placing the order.
            </p>

            <form className="mt-4 space-y-4" onSubmit={submit}>
                <div>
                    <p className="text-xs font-medium text-muted-foreground">
                        Fulfilment
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {(['delivery', 'pickup'] as const).map((type) => {
                            const locked =
                                run.fulfilment_options?.type !== undefined &&
                                run.fulfilment_options.type !== type;

                            return (
                                <Button
                                    key={type}
                                    type="button"
                                    size="sm"
                                    variant={
                                        form.data.fulfilment_type === type
                                            ? 'default'
                                            : 'outline'
                                    }
                                    disabled={locked || form.processing}
                                    onClick={() => chooseType(type)}
                                >
                                    {type === 'delivery' ? 'Delivery' : 'Pickup'}
                                </Button>
                            );
                        })}
                    </div>
                </div>

                {slots.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No fulfilment slots are available yet.
                    </p>
                ) : (
                    <fieldset>
                        <legend className="text-xs font-medium text-muted-foreground">
                            Day and time
                        </legend>
                        <ul className="mt-2 space-y-2">
                            {slots.map((slot) => {
                                const selected = form.data.slot_id === slot.id;
                                const fee =
                                    typeof slot.fee === 'number'
                                        ? slot.fee
                                        : null;

                                return (
                                    <li key={slot.id}>
                                        <label
                                            className={`flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm ${
                                                selected
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-transparent bg-muted/40'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                className="mt-1"
                                                name="fulfilment-slot"
                                                value={slot.id}
                                                checked={selected}
                                                disabled={form.processing}
                                                onChange={() =>
                                                    form.setData(
                                                        'slot_id',
                                                        slot.id,
                                                    )
                                                }
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block font-medium">
                                                    {slotLabel(slot)}
                                                </span>
                                                {fee !== null && (
                                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                                        {fee === 0
                                                            ? 'No fee'
                                                            : `Fee $${fee.toFixed(2)}`}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </fieldset>
                )}

                {run.fulfilment_options_expires_at && (
                    <p className="text-xs text-muted-foreground">
                        Options refresh{' '}
                        {new Date(
                            run.fulfilment_options_expires_at,
                        ).toLocaleString('en-AU')}
                        .
                    </p>
                )}

                {formError && (
                    <p className="text-xs text-destructive">{formError}</p>
                )}

                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        form.processing ||
                        slots.length === 0 ||
                        form.data.slot_id === ''
                    }
                >
                    Use this {form.data.fulfilment_type} time
                </Button>
            </form>
        </section>
    );
}
