import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CircleAlert,
    Ellipsis,
    PackageCheck,
    Plus,
    ReceiptText,
    RefreshCw,
    ShoppingBasket,
    Store,
    Trash2,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    ProductPreference,
    ShoppingListItem,
    ShoppingWorkspace,
} from '@/features/shopping/types';

function formatDate(value: string) {
    return new Intl.DateTimeFormat('en-AU', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${value.slice(0, 10)}T00:00:00`));
}

function formatMoney(value: number, currency = 'AUD') {
    return new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency,
    }).format(value);
}

function ProductMatchForm({
    item,
    revision,
    retailers,
    preference,
}: {
    item: ShoppingListItem;
    revision: number;
    retailers: { id: number; name: string }[];
    preference: ProductPreference | null;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        retailer_id:
            item.product_match?.retail_product.retailer.id.toString() ??
            preference?.retailer_id?.toString() ??
            retailers[0]?.id.toString() ??
            '',
        name: item.product_match?.retail_product.name ?? '',
        brand:
            item.product_match?.retail_product.brand ??
            preference?.preferred_brand ??
            '',
        pack_quantity:
            item.product_match?.retail_product.pack_quantity?.toString() ?? '',
        pack_unit: item.product_match?.retail_product.pack_unit ?? '',
        price:
            item.product_match?.retail_product.current_price?.toString() ?? '',
        pack_count: item.product_match?.pack_count.toString() ?? '1',
        preferred: item.product_match?.preferred ?? preference !== null,
        accept_substitutes: preference?.accept_substitutes ?? true,
        maximum_price: preference?.maximum_price?.toString() ?? '',
        note: preference?.note ?? '',
        expected_revision: revision,
    });

    return (
        <div className="mt-2 px-1">
            <button
                type="button"
                className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                onClick={() => setOpen((value) => !value)}
            >
                <Store className="size-3.5" />
                {item.product_match
                    ? `${item.product_match.retail_product.retailer.name}: ${item.product_match.retail_product.name} · ${formatMoney(item.product_match.estimated_total)}`
                    : preference
                      ? `Match product · prefers ${preference.preferred_brand ?? preference.retailer?.name ?? 'saved choice'}`
                      : 'Match retailer product'}
            </button>
            {open && (
                <form
                    className="mt-3 grid gap-2 rounded-lg bg-muted/50 p-3 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            retailer_id: Number(data.retailer_id),
                            pack_quantity: data.pack_quantity
                                ? Number(data.pack_quantity)
                                : null,
                            price: Number(data.price),
                            pack_count: Number(data.pack_count),
                            maximum_price: data.maximum_price
                                ? Number(data.maximum_price)
                                : null,
                            expected_revision: revision,
                        }));
                        form.put(
                            `/shopping-list-items/${item.id}/product-match`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            },
                        );
                    }}
                >
                    <Select
                        value={form.data.retailer_id}
                        onValueChange={(value) =>
                            form.setData('retailer_id', value)
                        }
                    >
                        <SelectTrigger
                            aria-label={`Retailer for ${item.name}`}
                            className="w-full"
                        >
                            <SelectValue placeholder="Retailer" />
                        </SelectTrigger>
                        <SelectContent>
                            {retailers.map((retailer) => (
                                <SelectItem
                                    key={retailer.id}
                                    value={retailer.id.toString()}
                                >
                                    {retailer.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        aria-label={`Product name for ${item.name}`}
                        placeholder="Product name"
                        required
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                    <Input
                        aria-label={`Brand for ${item.name}`}
                        placeholder="Brand (optional)"
                        value={form.data.brand}
                        onChange={(event) =>
                            form.setData('brand', event.target.value)
                        }
                    />
                    <div className="grid grid-cols-2 gap-2">
                        <Input
                            aria-label={`Pack quantity for ${item.name}`}
                            placeholder="Pack qty"
                            type="number"
                            min="0"
                            step="any"
                            value={form.data.pack_quantity}
                            onChange={(event) =>
                                form.setData(
                                    'pack_quantity',
                                    event.target.value,
                                )
                            }
                        />
                        <Input
                            aria-label={`Pack unit for ${item.name}`}
                            placeholder="Pack unit"
                            value={form.data.pack_unit}
                            onChange={(event) =>
                                form.setData('pack_unit', event.target.value)
                            }
                        />
                    </div>
                    <Input
                        aria-label={`Unit price for ${item.name}`}
                        placeholder="Unit price"
                        required
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.price}
                        onChange={(event) =>
                            form.setData('price', event.target.value)
                        }
                    />
                    <Input
                        aria-label={`Pack count for ${item.name}`}
                        placeholder="Packs"
                        required
                        type="number"
                        min="1"
                        value={form.data.pack_count}
                        onChange={(event) =>
                            form.setData('pack_count', event.target.value)
                        }
                    />
                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Checkbox
                            checked={form.data.preferred}
                            onCheckedChange={(checked) =>
                                form.setData('preferred', Boolean(checked))
                            }
                        />
                        Remember this product preference
                    </label>
                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Checkbox
                            checked={form.data.accept_substitutes}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'accept_substitutes',
                                    Boolean(checked),
                                )
                            }
                        />
                        Accept substitutes
                    </label>
                    <Button
                        size="sm"
                        className="sm:col-span-2"
                        disabled={form.processing}
                    >
                        Save product match
                    </Button>
                </form>
            )}
        </div>
    );
}

function ShoppingItemRow({
    item,
    revision,
    stale,
    retailers,
    preference,
}: {
    item: ShoppingListItem;
    revision: number;
    stale: boolean;
    retailers: { id: number; name: string }[];
    preference: ProductPreference | null;
}) {
    const form = useForm<{
        name: string;
        quantity: string | number | null;
        unit: string;
        note: string;
        expected_revision: number;
    }>({
        name: item.name,
        quantity: item.quantity?.toString() ?? '',
        unit: item.unit ?? '',
        note: item.note ?? '',
        expected_revision: revision,
    });
    const updateState = (
        changes: Record<string, boolean>,
        mergeSafe = false,
    ) => {
        router.put(
            `/shopping-list-items/${item.id}`,
            {
                ...changes,
                ...(mergeSafe ? {} : { expected_revision: revision }),
            },
            {
                preserveScroll: true,
            },
        );
    };
    const sourceMeals = [
        ...new Set(
            item.sources
                .map((source) => source.planned_meal?.title)
                .filter((title): title is string => Boolean(title)),
        ),
    ];
    const submitItem = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            expected_revision: revision,
            quantity: data.quantity === '' ? null : Number(data.quantity),
            unit: data.unit.trim() || null,
            note: data.note.trim() || null,
        }));
        form.put(`/shopping-list-items/${item.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <div
            className={`group grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3 px-1 py-3 ${!item.included || item.in_pantry ? 'opacity-60' : ''}`}
        >
            <Checkbox
                className="mt-2 size-5"
                aria-label={`Mark ${item.name} as ${item.checked ? 'not bought' : 'bought'}`}
                checked={item.checked}
                disabled={stale || !item.included || item.in_pantry}
                onCheckedChange={(checked) =>
                    updateState({ checked: Boolean(checked) }, true)
                }
            />
            <div className="min-w-0">
                <form onSubmit={submitItem}>
                    <div className="grid gap-2 sm:grid-cols-[minmax(10rem,1fr)_6rem_5rem]">
                        <Input
                            aria-label={`${item.name} name`}
                            value={form.data.name}
                            disabled={stale}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            className={`h-9 border-transparent bg-transparent px-1 shadow-none hover:border-input focus-visible:border-input ${item.checked ? 'line-through' : ''}`}
                        />
                        <Input
                            aria-label={`${item.name} quantity`}
                            value={form.data.quantity ?? ''}
                            disabled={stale}
                            type="number"
                            min="0"
                            step="any"
                            placeholder="Qty"
                            onChange={(event) =>
                                form.setData('quantity', event.target.value)
                            }
                            className="h-9 border-transparent bg-transparent px-1 shadow-none hover:border-input focus-visible:border-input"
                        />
                        <Input
                            aria-label={`${item.name} unit`}
                            value={form.data.unit}
                            disabled={stale}
                            placeholder="Unit"
                            onChange={(event) =>
                                form.setData('unit', event.target.value)
                            }
                            className="h-9 border-transparent bg-transparent px-1 shadow-none hover:border-input focus-visible:border-input"
                        />
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-xs text-muted-foreground">
                        {sourceMeals.length > 0 && (
                            <span>For {sourceMeals.join(', ')}</span>
                        )}
                        {item.source_kind !== 'recipe' && (
                            <span>
                                {item.source_kind === 'staple'
                                    ? 'Household staple'
                                    : 'Added manually'}
                            </span>
                        )}
                        {item.optional && <span>Optional</span>}
                        {item.in_pantry && <span>Already in pantry</span>}
                        {!item.included && <span>Excluded</span>}
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <Input
                            aria-label={`${item.name} note`}
                            value={form.data.note}
                            disabled={stale}
                            placeholder="Add a note"
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                            className="h-8 border-transparent bg-transparent px-1 text-xs shadow-none hover:border-input focus-visible:border-input"
                        />
                        {form.isDirty && (
                            <Button
                                type="submit"
                                size="sm"
                                variant="ghost"
                                disabled={form.processing || stale}
                            >
                                Save
                            </Button>
                        )}
                    </div>
                </form>
                {!stale && item.included && !item.in_pantry && (
                    <ProductMatchForm
                        item={item}
                        revision={revision}
                        retailers={retailers}
                        preference={preference}
                    />
                )}
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label={`Actions for ${item.name}`}
                        disabled={stale}
                        className="mt-1"
                    >
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        onSelect={() =>
                            updateState({ in_pantry: !item.in_pantry })
                        }
                    >
                        <PackageCheck />
                        {item.in_pantry
                            ? 'Move back to list'
                            : 'Already in pantry'}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            updateState({ included: !item.included })
                        }
                    >
                        {item.included ? 'Exclude item' : 'Include item'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() =>
                            router.delete(`/shopping-list-items/${item.id}`, {
                                data: { expected_revision: revision },
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 /> Remove
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function AddItemForm({
    listId,
    revision,
}: {
    listId: number;
    revision: number;
}) {
    const form = useForm({
        name: '',
        quantity: '' as string | number | null,
        unit: '',
        note: '',
        staple: false,
        expected_revision: revision,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            expected_revision: revision,
            quantity: data.quantity === '' ? null : Number(data.quantity),
            unit: data.unit.trim() || null,
            note: data.note.trim() || null,
        }));
        form.post(`/shopping-lists/${listId}/items`, {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'quantity', 'unit', 'note'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-2 py-4 sm:grid-cols-[minmax(10rem,1fr)_6rem_5rem_auto]"
        >
            <Input
                aria-label="New shopping item"
                placeholder="Add an item"
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                required
            />
            <Input
                aria-label="New item quantity"
                placeholder="Qty"
                type="number"
                min="0"
                step="any"
                value={form.data.quantity ?? ''}
                onChange={(event) =>
                    form.setData('quantity', event.target.value)
                }
            />
            <Input
                aria-label="New item unit"
                placeholder="Unit"
                value={form.data.unit}
                onChange={(event) => form.setData('unit', event.target.value)}
            />
            <Button size="sm" disabled={form.processing}>
                <Plus /> Add
            </Button>
            <label className="flex items-center gap-2 text-xs text-muted-foreground sm:col-span-4">
                <Checkbox
                    checked={form.data.staple}
                    onCheckedChange={(checked) =>
                        form.setData('staple', Boolean(checked))
                    }
                />
                Household staple
            </label>
        </form>
    );
}

function MissingMealIngredients({
    meal,
    listId,
    revision,
}: {
    meal: { id: number; title: string; date: string };
    listId: number;
    revision: number;
}) {
    const form = useForm({ ingredients_text: '', expected_revision: revision });

    return (
        <form
            className="border-t py-3 first:border-t-0"
            onSubmit={(event) => {
                event.preventDefault();
                const ingredients = form.data.ingredients_text
                    .split('\n')
                    .map((line) => line.trim())
                    .filter(Boolean)
                    .map((line) => {
                        const [name, quantity, unit] = line
                            .split('|')
                            .map((part) => part.trim());

                        return {
                            name,
                            quantity: quantity ? Number(quantity) : null,
                            unit: unit || null,
                        };
                    });
                form.transform(() => ({
                    ingredients,
                    expected_revision: revision,
                }));
                form.post(
                    `/shopping-lists/${listId}/meals/${meal.id}/ingredients`,
                    { preserveScroll: true },
                );
            }}
        >
            <p className="text-xs font-medium">
                {formatDate(meal.date)} · {meal.title}
            </p>
            <textarea
                aria-label={`Ingredients for ${meal.title}`}
                className="mt-2 min-h-20 w-full resize-y rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                placeholder={
                    'Ingredient | quantity | unit\nChicken breast | 500 | g'
                }
                required
                value={form.data.ingredients_text}
                onChange={(event) =>
                    form.setData('ingredients_text', event.target.value)
                }
            />
            <Button
                size="sm"
                variant="outline"
                className="mt-2"
                disabled={form.processing}
            >
                Add meal ingredients
            </Button>
        </form>
    );
}

function BudgetEditor({
    planId,
    budget,
}: {
    planId: number;
    budget: ShoppingWorkspace['budget'];
}) {
    const form = useForm({
        amount: (
            budget.plan_override ??
            budget.household_default ??
            ''
        ).toString(),
        household_default: budget.plan_override === null,
    });
    const difference =
        budget.effective === null
            ? null
            : budget.effective - budget.projected_total;

    return (
        <section className="mt-6 rounded-xl bg-muted/40 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <Wallet className="size-4" /> Budget
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {budget.effective === null
                            ? 'No budget set yet'
                            : `${formatMoney(budget.projected_total)} projected of ${formatMoney(budget.effective)}${budget.unmatched_items > 0 ? ` · ${budget.unmatched_items} unmatched` : ''}`}
                    </p>
                </div>
                {difference !== null && (
                    <Badge
                        variant={difference < 0 ? 'destructive' : 'secondary'}
                    >
                        {difference < 0
                            ? `${formatMoney(Math.abs(difference))} over`
                            : `${formatMoney(difference)} left`}
                    </Badge>
                )}
            </div>
            <form
                className="mt-3 flex flex-wrap items-center gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        amount: Number(data.amount),
                        household_default: data.household_default,
                    }));
                    form.put(`/meal-plans/${planId}/shopping-budget`, {
                        preserveScroll: true,
                    });
                }}
            >
                <Input
                    aria-label="Shopping budget"
                    className="w-32"
                    type="number"
                    min="0.01"
                    step="0.01"
                    required
                    placeholder="Budget"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                />
                <label className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Checkbox
                        checked={form.data.household_default}
                        onCheckedChange={(checked) =>
                            form.setData('household_default', Boolean(checked))
                        }
                    />
                    Use as household default
                </label>
                <Button size="sm" variant="outline" disabled={form.processing}>
                    Save budget
                </Button>
            </form>
        </section>
    );
}

function OrderRecorder({
    listId,
    retailers,
    orders,
}: {
    listId: number;
    retailers: { id: number; name: string }[];
    orders: NonNullable<ShoppingWorkspace['shopping_list']>['orders'];
}) {
    const form = useForm({ actual_total: '', retailer_id: '' });

    return (
        <section className="mt-8 border-t pt-6">
            <p className="flex items-center gap-2 text-sm font-medium">
                <ReceiptText className="size-4" /> Order history
            </p>
            <form
                className="mt-3 flex flex-wrap gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        actual_total: Number(data.actual_total),
                        retailer_id: data.retailer_id
                            ? Number(data.retailer_id)
                            : null,
                    }));
                    form.post(`/shopping-lists/${listId}/orders`, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Select
                    value={form.data.retailer_id}
                    onValueChange={(value) =>
                        form.setData('retailer_id', value)
                    }
                >
                    <SelectTrigger aria-label="Order retailer" className="w-40">
                        <SelectValue placeholder="Retailer" />
                    </SelectTrigger>
                    <SelectContent>
                        {retailers.map((retailer) => (
                            <SelectItem
                                key={retailer.id}
                                value={retailer.id.toString()}
                            >
                                {retailer.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Input
                    aria-label="Actual order total"
                    className="w-36"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    placeholder="Actual total"
                    value={form.data.actual_total}
                    onChange={(event) =>
                        form.setData('actual_total', event.target.value)
                    }
                />
                <Button size="sm" disabled={form.processing}>
                    Record order
                </Button>
            </form>
            {orders.length > 0 && (
                <ul className="mt-4 divide-y text-xs">
                    {orders.map((order) => (
                        <li
                            key={order.id}
                            className="flex justify-between gap-3 py-2"
                        >
                            <span>
                                {order.retailer?.name ?? 'Shop'} ·{' '}
                                {new Date(order.recorded_at).toLocaleDateString(
                                    'en-AU',
                                )}
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(
                                    order.actual_total,
                                    order.currency,
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function ShoppingShow({
    workspace,
}: {
    workspace: ShoppingWorkspace;
}) {
    const {
        plan,
        shopping_list: shoppingList,
        missing_meals: missingMeals,
        retailers,
        product_preferences: productPreferences,
        budget,
    } = workspace;
    const remaining =
        shoppingList?.items.filter(
            (item) => item.included && !item.in_pantry && !item.checked,
        ).length ?? 0;
    const included =
        shoppingList?.items.filter((item) => item.included && !item.in_pantry)
            .length ?? 0;
    const completionForm = useForm({
        expected_revision: shoppingList?.revision ?? 0,
    });
    const completionError = Object.values(completionForm.errors)[0];

    return (
        <>
            <Head title={`Shopping — ${plan.title}`} />
            <main className="min-h-0 flex-1 overflow-y-auto bg-background">
                <div className="mx-auto w-full max-w-4xl px-5 py-8 sm:px-8 lg:py-10">
                    <Button asChild variant="ghost" size="sm" className="-ml-2">
                        <Link href={`/meal-plans/${plan.id}`}>
                            <ArrowLeft /> Back to plan
                        </Link>
                    </Button>

                    <header className="mt-5 flex flex-wrap items-start justify-between gap-5 border-b pb-6">
                        <div>
                            <div className="flex items-center gap-2">
                                <ShoppingBasket className="size-5 text-primary" />
                                <h1 className="text-xl font-semibold">
                                    Shopping list
                                </h1>
                                {shoppingList && (
                                    <Badge
                                        variant="secondary"
                                        className="font-normal"
                                    >
                                        {shoppingList.status === 'completed'
                                            ? 'Completed'
                                            : `${remaining} remaining`}
                                    </Badge>
                                )}
                            </div>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {plan.title} · {formatDate(plan.starts_on)} –{' '}
                                {formatDate(plan.ends_on)}
                            </p>
                        </div>
                        {shoppingList && (
                            <Button
                                disabled={
                                    remaining > 0 ||
                                    missingMeals.length > 0 ||
                                    shoppingList.stale_at !== null ||
                                    completionForm.processing
                                }
                                onClick={() => {
                                    completionForm.transform(() => ({
                                        expected_revision:
                                            shoppingList.revision,
                                    }));
                                    completionForm.post(
                                        `/shopping-lists/${shoppingList.id}/complete`,
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <Check /> Complete shop
                            </Button>
                        )}
                    </header>
                    {completionError && (
                        <p
                            className="mt-3 text-sm text-destructive"
                            role="alert"
                        >
                            {completionError}
                        </p>
                    )}

                    <BudgetEditor planId={plan.id} budget={budget} />

                    {!shoppingList ? (
                        <section className="py-12 text-center">
                            <p className="text-sm font-medium">
                                Start this plan&rsquo;s shopping list
                            </p>
                            <p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                                Chef will aggregate ingredients from the exact
                                recipe versions attached to this confirmed plan.
                            </p>
                            <Button
                                className="mt-5"
                                onClick={() =>
                                    router.post(
                                        `/meal-plans/${plan.id}/shopping-list`,
                                    )
                                }
                            >
                                <ShoppingBasket /> Generate list
                            </Button>
                        </section>
                    ) : (
                        <>
                            {shoppingList.stale_at && (
                                <section className="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-amber-50 px-4 py-3 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                                    <div>
                                        <p className="flex items-center gap-2 text-sm font-medium">
                                            <CircleAlert className="size-4" />
                                            Plan changes need reviewing
                                        </p>
                                        <p className="mt-1 text-xs">
                                            {shoppingList.stale_reason}
                                        </p>
                                        {shoppingList.stale_diff && (
                                            <ul className="mt-2 space-y-1 text-xs">
                                                {shoppingList.stale_diff.changes.map(
                                                    (change) => (
                                                        <li
                                                            key={
                                                                change.revision
                                                            }
                                                        >
                                                            Revision{' '}
                                                            {change.revision}:{' '}
                                                            {change.summary}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                    </div>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/meal-plans/${plan.id}/shopping-list`,
                                            )
                                        }
                                    >
                                        <RefreshCw /> Regenerate
                                    </Button>
                                </section>
                            )}

                            {missingMeals.length > 0 && (
                                <section className="mt-6 rounded-xl bg-muted/50 px-4 py-3">
                                    <p className="text-sm font-medium">
                                        {missingMeals.length}{' '}
                                        {missingMeals.length === 1
                                            ? 'meal needs'
                                            : 'meals need'}{' '}
                                        structured ingredients
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        These meals were planned without a
                                        recipe, so Chef has not guessed their
                                        ingredients. Add and confirm the
                                        ingredients for each meal before
                                        completing the shop.
                                    </p>
                                    <div className="mt-2">
                                        {missingMeals.map((meal) => (
                                            <MissingMealIngredients
                                                key={meal.id}
                                                meal={meal}
                                                listId={shoppingList.id}
                                                revision={shoppingList.revision}
                                            />
                                        ))}
                                    </div>
                                </section>
                            )}

                            <section className="mt-8">
                                <div className="flex flex-wrap items-end justify-between gap-3">
                                    <div>
                                        <h2 className="font-medium">Items</h2>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {included} included · revision{' '}
                                            {shoppingList.revision}
                                        </p>
                                    </div>
                                </div>
                                <div className="mt-3 divide-y border-y">
                                    {shoppingList.items.map((item) => (
                                        <ShoppingItemRow
                                            key={item.id}
                                            item={item}
                                            revision={shoppingList.revision}
                                            stale={
                                                shoppingList.stale_at !== null
                                            }
                                            retailers={retailers}
                                            preference={
                                                productPreferences.find(
                                                    (preference) =>
                                                        preference.normalized_item_name ===
                                                        item.normalized_name,
                                                ) ?? null
                                            }
                                        />
                                    ))}
                                    {shoppingList.items.length === 0 && (
                                        <p className="px-1 py-8 text-sm text-muted-foreground">
                                            No recipe ingredients were
                                            available. Add the first item below.
                                        </p>
                                    )}
                                </div>
                                {!shoppingList.stale_at && (
                                    <AddItemForm
                                        listId={shoppingList.id}
                                        revision={shoppingList.revision}
                                    />
                                )}
                            </section>
                            {shoppingList.status === 'completed' && (
                                <OrderRecorder
                                    listId={shoppingList.id}
                                    retailers={retailers}
                                    orders={shoppingList.orders}
                                />
                            )}
                        </>
                    )}
                </div>
            </main>
        </>
    );
}
