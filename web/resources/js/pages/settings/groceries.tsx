import { Form, Head, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import GrocerySettingsController from '@/actions/App/Http/Controllers/Settings/GrocerySettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/groceries';
import { destroy } from '@/routes/retailer-product-preferences';

type PurchasePolicy = {
    provider: 'coles';
    home_brand_preference: 'allow' | 'prefer' | 'avoid';
    bulk_preference: 'allow' | 'avoid';
    organic_preference: 'no_preference' | 'prefer';
    preferred_brands: string[];
    default_basket_target_cents: number | null;
    fingerprint: string;
};

type ProductPreference = {
    id: number;
    ingredient: string;
    form: string | null;
    sku: string;
    product_title: string | null;
    created_at: string;
};

type Props = {
    policy: PurchasePolicy;
    product_preferences: ProductPreference[];
    can: { update: boolean };
};

const selectClassName =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50';

function SavedPreference({ preference }: { preference: ProductPreference }) {
    const request = useHttp<Record<string, never>, { preference: unknown }>({});
    const [revoked, setRevoked] = useState(false);

    if (revoked) {
        return null;
    }

    return (
        <li className="flex items-start justify-between gap-4 border-b py-4 last:border-0">
            <div className="min-w-0">
                <p className="font-medium">{preference.ingredient}</p>
                <p className="mt-1 text-sm text-muted-foreground">
                    {preference.product_title ?? preference.sku}
                    {preference.form ? ` · ${preference.form}` : ''}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={request.processing}
                onClick={async () => {
                    await request.delete(destroy.url(preference.id));
                    setRevoked(true);
                }}
            >
                Remove
            </Button>
        </li>
    );
}

export default function Groceries({ policy, product_preferences, can }: Props) {
    const [brands, setBrands] = useState(policy.preferred_brands.join(', '));
    const [target, setTarget] = useState(
        policy.default_basket_target_cents === null
            ? ''
            : (policy.default_basket_target_cents / 100).toFixed(2),
    );
    const normalizedBrands = brands
        .split(',')
        .map((brand) => brand.trim())
        .filter((brand) => brand.length > 0);
    const targetCents =
        target.trim() === '' || Number.isNaN(Number(target))
            ? ''
            : String(Math.round(Number(target) * 100));

    return (
        <>
            <Head title="Grocery settings" />
            <h1 className="sr-only">Grocery settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Grocery preferences"
                    description="Chef applies these after product safety and suitability checks. You can still change anything at Coles before checkout."
                />

                <Form
                    {...GrocerySettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {normalizedBrands.map((brand) => (
                                <input
                                    key={brand}
                                    type="hidden"
                                    name="preferred_brands[]"
                                    value={brand}
                                />
                            ))}
                            {normalizedBrands.length === 0 && (
                                <input
                                    type="hidden"
                                    name="preferred_brands"
                                    value=""
                                />
                            )}
                            <input
                                type="hidden"
                                name="default_basket_target_cents"
                                value={targetCents}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="home_brand_preference">
                                    Coles-brand products
                                </Label>
                                <select
                                    id="home_brand_preference"
                                    name="home_brand_preference"
                                    className={selectClassName}
                                    defaultValue={policy.home_brand_preference}
                                    disabled={!can.update}
                                >
                                    <option value="allow">Allow</option>
                                    <option value="prefer">Prefer</option>
                                    <option value="avoid">Avoid</option>
                                </select>
                                <InputError
                                    message={errors.home_brand_preference}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bulk_preference">
                                    Bulk packs
                                </Label>
                                <select
                                    id="bulk_preference"
                                    name="bulk_preference"
                                    className={selectClassName}
                                    defaultValue={policy.bulk_preference}
                                    disabled={!can.update}
                                >
                                    <option value="avoid">Avoid</option>
                                    <option value="allow">Allow</option>
                                </select>
                                <InputError message={errors.bulk_preference} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="organic_preference">
                                    Organic products
                                </Label>
                                <select
                                    id="organic_preference"
                                    name="organic_preference"
                                    className={selectClassName}
                                    defaultValue={policy.organic_preference}
                                    disabled={!can.update}
                                >
                                    <option value="no_preference">
                                        No preference
                                    </option>
                                    <option value="prefer">Prefer</option>
                                </select>
                                <InputError
                                    message={errors.organic_preference}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="preferred_brands">
                                    Preferred brands
                                </Label>
                                <Input
                                    id="preferred_brands"
                                    value={brands}
                                    onChange={(event) =>
                                        setBrands(event.target.value)
                                    }
                                    placeholder="Barilla, Mutti"
                                    disabled={!can.update}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Separate brands with commas, in preference
                                    order.
                                </p>
                                <InputError
                                    message={
                                        errors.preferred_brands ??
                                        errors['preferred_brands.0']
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_basket_target">
                                    Default basket target (AUD)
                                </Label>
                                <Input
                                    id="default_basket_target"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={target}
                                    onChange={(event) =>
                                        setTarget(event.target.value)
                                    }
                                    placeholder="No target"
                                    disabled={!can.update}
                                />
                                <p className="text-xs text-muted-foreground">
                                    A meal plan can set a different target. Chef
                                    pauses before changing Coles if the selected
                                    products exceed it.
                                </p>
                                <InputError
                                    message={errors.default_basket_target_cents}
                                />
                            </div>

                            {can.update ? (
                                <Button disabled={processing}>Save</Button>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    A household owner or admin can change this
                                    policy.
                                </p>
                            )}
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Products to prefer next time"
                    description="These explicit choices are considered before Chef compares other valid products."
                />
                {product_preferences.length === 0 ? (
                    <p className="text-sm leading-6 text-muted-foreground">
                        No saved product choices yet. Save one from a completed
                        Coles basket.
                    </p>
                ) : (
                    <ul>
                        {product_preferences.map((preference) => (
                            <SavedPreference
                                key={preference.id}
                                preference={preference}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Groceries.layout = {
    breadcrumbs: [
        {
            title: 'Grocery settings',
            href: edit(),
        },
    ],
};
