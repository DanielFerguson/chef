import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUp,
    Check,
    CircleAlert,
    Ellipsis,
    LoaderCircle,
    MessageCircle,
    PackageCheck,
    Plus,
    ReceiptText,
    RefreshCw,
    ShoppingBasket,
    Store,
    Trash2,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
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
import { AssistantMessage } from '@/features/meal-plans/assistant-message';
import { useChefConversation } from '@/features/meal-plans/use-chef-conversation';
import type {
    ProductPreference,
    ShoppingListItem,
    ShoppingListItemCategory,
    ShoppingWorkspace,
} from '@/features/shopping/types';

const dateFormatter = new Intl.DateTimeFormat('en-AU', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const moneyFormatter = new Intl.NumberFormat('en-AU', {
    style: 'currency',
    currency: 'AUD',
});

function formatDate(value: string) {
    return dateFormatter.format(new Date(`${value.slice(0, 10)}T00:00:00`));
}

function formatMoney(value: number) {
    return moneyFormatter.format(value);
}

function ShoppingConversation({
    conversation,
    planId,
}: {
    conversation: ShoppingWorkspace['conversation'];
    planId: number;
}) {
    const { error, input, messages, sending, sendMessage, setInput } =
        useChefConversation(conversation);
    const latestAssistant = messages.findLast(
        (message) => message.role === 'assistant' && message.content !== '',
    );

    return (
        <section className="mt-6 border-y py-4">
            <div className="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <MessageCircle className="size-4 text-primary" /> Continue
                    with Chef
                </p>
                <Button
                    asChild
                    size="sm"
                    variant="ghost"
                    className="-ml-3 sm:ml-0"
                >
                    <Link href={`/meal-plans/${planId}`}>
                        View full conversation
                    </Link>
                </Button>
            </div>
            {latestAssistant && (
                <div className="mt-3 max-w-[48em] text-sm">
                    <AssistantMessage content={latestAssistant.content} />
                </div>
            )}
            <form
                onSubmit={sendMessage}
                className="mt-3 rounded-xl border bg-card p-2 shadow-sm"
            >
                <textarea
                    value={input}
                    onChange={(event) => setInput(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            event.currentTarget.form?.requestSubmit();
                        }
                    }}
                    aria-label="Message Chef about shopping"
                    placeholder="Tell Chef what to add, what you already have, or what to change…"
                    className="min-h-16 w-full resize-none bg-transparent px-2 py-1 text-sm outline-none placeholder:text-muted-foreground"
                />
                {error && (
                    <p
                        role="alert"
                        className="px-2 pb-2 text-xs text-destructive"
                    >
                        {error}
                    </p>
                )}
                <div className="flex items-center justify-between">
                    <p className="px-2 text-xs text-muted-foreground">
                        This continues the same plan conversation
                    </p>
                    <Button
                        size="icon"
                        type="submit"
                        disabled={sending || input.trim() === ''}
                    >
                        {sending ? (
                            <LoaderCircle className="animate-spin" />
                        ) : (
                            <ArrowUp />
                        )}
                        <span className="sr-only">Send shopping message</span>
                    </Button>
                </div>
            </form>
        </section>
    );
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
    shoppingCategories,
    retailers,
    preference,
}: {
    item: ShoppingListItem;
    revision: number;
    stale: boolean;
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
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
        changes: Record<string, boolean | string>,
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
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>Move to</DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            {shoppingCategories.map((category) => (
                                <DropdownMenuItem
                                    key={category.value}
                                    onSelect={() =>
                                        updateState({
                                            category: category.value,
                                        })
                                    }
                                >
                                    <span className="w-4">
                                        {item.category === category.value && (
                                            <Check />
                                        )}
                                    </span>
                                    {category.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
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
                                {formatMoney(order.actual_total)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

type PreparedShoppingList = NonNullable<ShoppingWorkspace['shopping_list']>;
type MissingMeal = ShoppingWorkspace['missing_meals'][number];

function StartShoppingList({ onPrepare }: { onPrepare: () => void }) {
    return (
        <section className="py-12">
            <div className="mx-auto max-w-lg text-center">
                <p className="text-sm font-medium">
                    Prepare this plan&rsquo;s shopping list
                </p>
                <p className="mt-2 text-sm text-muted-foreground">
                    Chef will prepare any missing recipes, scale them for your
                    servings, and combine their ingredients. You&rsquo;ll review
                    the result before shopping.
                </p>
                <Button className="mt-5" onClick={onPrepare}>
                    <ShoppingBasket /> Prepare shopping list
                </Button>
            </div>
        </section>
    );
}

function FailedMealRecovery({
    failedMeals,
    shoppingList,
}: {
    failedMeals: MissingMeal[];
    shoppingList: PreparedShoppingList;
}) {
    return (
        <details className="mt-4 border-t pt-3 text-left text-xs text-muted-foreground">
            <summary className="cursor-pointer font-medium text-foreground">
                Advanced manual recovery
            </summary>
            <p className="mt-2">
                Retry is recommended. If a meal is deliberately unusual, you can
                record its ingredients manually instead.
            </p>
            <div className="mt-2">
                {failedMeals.map((meal) => (
                    <MissingMealIngredients
                        key={meal.id}
                        meal={meal}
                        listId={shoppingList.id}
                        revision={shoppingList.revision}
                    />
                ))}
            </div>
        </details>
    );
}

function PreparingShoppingList({
    active,
    failedMeals,
    onPrepare,
    recipePreparation,
    shoppingList,
}: {
    active: boolean;
    failedMeals: MissingMeal[];
    onPrepare: () => void;
    recipePreparation: ShoppingWorkspace['recipe_preparation'];
    shoppingList: PreparedShoppingList;
}) {
    return (
        <section className="py-12">
            {recipePreparation.failed > 0 ? (
                <div className="mx-auto max-w-lg rounded-xl bg-destructive/5 px-5 py-4">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <CircleAlert className="size-4" /> Chef could not
                        prepare {recipePreparation.failed}{' '}
                        {recipePreparation.failed === 1 ? 'recipe' : 'recipes'}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Retry the preparation. Manual ingredient entry remains
                        available as an advanced recovery option.
                    </p>
                    <Button className="mt-4" size="sm" onClick={onPrepare}>
                        <RefreshCw /> Retry preparation
                    </Button>
                    <FailedMealRecovery
                        failedMeals={failedMeals}
                        shoppingList={shoppingList}
                    />
                </div>
            ) : (
                <div className="mx-auto max-w-lg text-center">
                    <LoaderCircle className="mx-auto size-6 animate-spin text-primary" />
                    <p className="mt-3 text-sm font-medium">
                        {active
                            ? 'Preparing your recipes'
                            : 'Combining your ingredients'}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {recipePreparation.ready} of{' '}
                        {recipePreparation.required} recipes are ready. This
                        page will update automatically.
                    </p>
                </div>
            )}
        </section>
    );
}

function ReadyShoppingList({
    budget,
    failedMeals,
    included,
    missingMeals,
    onPrepare,
    planId,
    productPreferences,
    recipePreparation,
    retailers,
    shoppingCategories,
    shoppingList,
}: {
    budget: ShoppingWorkspace['budget'];
    failedMeals: MissingMeal[];
    included: number;
    missingMeals: ShoppingWorkspace['missing_meals'];
    onPrepare: () => void;
    planId: number;
    productPreferences: ShoppingWorkspace['product_preferences'];
    recipePreparation: ShoppingWorkspace['recipe_preparation'];
    retailers: ShoppingWorkspace['retailers'];
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
    shoppingList: PreparedShoppingList;
}) {
    const active = recipePreparation.preparing > 0;
    const groupedItems: {
        value: ShoppingListItemCategory;
        label: string;
        items: ShoppingListItem[];
    }[] = [];

    for (const category of shoppingCategories) {
        const items = shoppingList.items.filter(
            (item) => item.category === category.value,
        );

        if (items.length > 0) {
            groupedItems.push({ ...category, items });
        }
    }

    return (
        <>
            {shoppingList.stale_at && (
                <section className="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-amber-50 px-4 py-3 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                    <div>
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <CircleAlert className="size-4" /> Plan changes need
                            reviewing
                        </p>
                        <p className="mt-1 text-xs">
                            {shoppingList.stale_reason}
                        </p>
                        {shoppingList.stale_diff && (
                            <ul className="mt-2 space-y-1 text-xs">
                                {shoppingList.stale_diff.changes.map(
                                    (change) => (
                                        <li key={change.revision}>
                                            Revision {change.revision}:{' '}
                                            {change.summary}
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                    </div>
                    <Button size="sm" onClick={onPrepare}>
                        <RefreshCw /> Regenerate
                    </Button>
                </section>
            )}

            {missingMeals.length > 0 && (
                <section className="mt-6 rounded-xl bg-muted/40 px-4 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium">
                                {recipePreparation.failed > 0
                                    ? `${recipePreparation.failed} recipe preparation ${recipePreparation.failed === 1 ? 'needs' : 'need'} another try`
                                    : `Preparing ${missingMeals.length} ${missingMeals.length === 1 ? 'recipe' : 'recipes'}`}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                Chef is building the structured recipes. You do
                                not need to type their ingredients yourself.
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={onPrepare}
                            disabled={active}
                        >
                            {active ? (
                                <LoaderCircle className="animate-spin" />
                            ) : (
                                <RefreshCw />
                            )}{' '}
                            {active ? 'Preparing' : 'Retry'}
                        </Button>
                    </div>
                    {recipePreparation.failed > 0 && (
                        <FailedMealRecovery
                            failedMeals={failedMeals}
                            shoppingList={shoppingList}
                        />
                    )}
                </section>
            )}

            <section className="mt-8">
                <div>
                    <h2 className="font-medium">Items</h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {included} included · revision {shoppingList.revision}
                    </p>
                </div>
                <div className="mt-5 space-y-7">
                    {groupedItems.map((category) => (
                        <section
                            key={category.value}
                            aria-labelledby={`shopping-category-${category.value}`}
                        >
                            <div className="flex items-baseline justify-between gap-3 px-1">
                                <h3
                                    id={`shopping-category-${category.value}`}
                                    className="text-sm font-medium"
                                >
                                    {category.label}
                                </h3>
                                <span className="text-xs text-muted-foreground">
                                    {category.items.length}{' '}
                                    {category.items.length === 1
                                        ? 'item'
                                        : 'items'}
                                </span>
                            </div>
                            <div className="mt-2 divide-y border-y">
                                {category.items.map((item) => (
                                    <ShoppingItemRow
                                        key={item.id}
                                        item={item}
                                        revision={shoppingList.revision}
                                        stale={shoppingList.stale_at !== null}
                                        shoppingCategories={shoppingCategories}
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
                            </div>
                        </section>
                    ))}
                    {shoppingList.items.length === 0 && (
                        <p className="px-1 py-8 text-sm text-muted-foreground">
                            This plan does not need any groceries yet. Add a
                            household item or return to the plan to review its
                            meals.
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
            {shoppingList.items.length > 0 && (
                <details className="mt-8 border-t pt-4">
                    <summary className="cursor-pointer text-sm font-medium">
                        Budget and estimate
                    </summary>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Optional. Set this after the ingredient and pantry
                        review if it helps guide product choices.
                    </p>
                    <BudgetEditor planId={planId} budget={budget} />
                </details>
            )}
            {shoppingList.status === 'completed' && (
                <OrderRecorder
                    listId={shoppingList.id}
                    retailers={retailers}
                    orders={shoppingList.orders}
                />
            )}
        </>
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
        recipe_preparation: recipePreparation,
        conversation,
        shopping_categories: shoppingCategories,
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
    const failedMeals = missingMeals.reduce<MissingMeal[]>((meals, meal) => {
        if (meal.preparation_status === 'failed') {
            meals.push(meal);
        }

        return meals;
    }, []);
    const completionForm = useForm({
        expected_revision: shoppingList?.revision ?? 0,
    });
    const completionError = Object.values(completionForm.errors)[0];
    const listPreparing = shoppingList !== null && shoppingList.revision === 0;
    const preparationActive = recipePreparation.preparing > 0;
    const shouldPoll = preparationActive || listPreparing;

    useEffect(() => {
        if (!shouldPoll) {
            return;
        }

        const interval = window.setInterval(
            () =>
                router.reload({
                    only: ['workspace'],
                }),
            2000,
        );

        return () => window.clearInterval(interval);
    }, [shouldPoll]);

    const prepareShopping = () =>
        router.post(
            `/meal-plans/${plan.id}/shopping-list`,
            {},
            { preserveScroll: true },
        );

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

                    <header className="mt-5 flex flex-col items-start gap-5 border-b pb-6 sm:flex-row sm:justify-between">
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
                                            : shoppingList.items.length === 0
                                              ? 'Preparing'
                                              : `${remaining} remaining`}
                                    </Badge>
                                )}
                                {!shoppingList && (
                                    <Badge
                                        variant="secondary"
                                        className="font-normal"
                                    >
                                        {recipePreparation.ready} of{' '}
                                        {recipePreparation.required} recipes
                                        ready
                                    </Badge>
                                )}
                            </div>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {plan.title} · {formatDate(plan.starts_on)} –{' '}
                                {formatDate(plan.ends_on)}
                            </p>
                        </div>
                        {shoppingList && !listPreparing && (
                            <Button
                                disabled={
                                    shoppingList.items.length === 0 ||
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

                    <ShoppingConversation
                        conversation={conversation}
                        planId={plan.id}
                    />

                    {!shoppingList ? (
                        <StartShoppingList onPrepare={prepareShopping} />
                    ) : listPreparing ? (
                        <PreparingShoppingList
                            active={preparationActive}
                            failedMeals={failedMeals}
                            onPrepare={prepareShopping}
                            recipePreparation={recipePreparation}
                            shoppingList={shoppingList}
                        />
                    ) : (
                        <ReadyShoppingList
                            budget={budget}
                            failedMeals={failedMeals}
                            included={included}
                            missingMeals={missingMeals}
                            onPrepare={prepareShopping}
                            planId={plan.id}
                            productPreferences={productPreferences}
                            recipePreparation={recipePreparation}
                            retailers={retailers}
                            shoppingCategories={shoppingCategories}
                            shoppingList={shoppingList}
                        />
                    )}
                </div>
            </main>
        </>
    );
}
