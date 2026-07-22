import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowUp,
    Check,
    ChevronDown,
    CircleAlert,
    Ellipsis,
    ExternalLink,
    Hand,
    List as ListIcon,
    LoaderCircle,
    LogIn,
    MessageCircle,
    PackageCheck,
    Pencil,
    Plus,
    ReceiptText,
    RefreshCw,
    ShoppingBasket,
    ShieldCheck,
    Table2,
    Trash2,
    Unplug,
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { AssistantMessage } from '@/features/meal-plans/assistant-message';
import { useChefConversation } from '@/features/meal-plans/use-chef-conversation';
import type {
    AutomationRun,
    CartAutomation,
    CartSnapshotLine,
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
    shoppingList,
}: {
    conversation: ShoppingWorkspace['conversation'];
    planId: number;
    shoppingList: ShoppingWorkspace['shopping_list'];
}) {
    const {
        error,
        input,
        messages,
        retryMessage,
        sending,
        sendMessage,
        setInput,
    } = useChefConversation(conversation);
    const latestAssistant = messages.findLast(
        (message) => message.role === 'assistant' && message.content !== '',
    );
    const [initialAssistantId] = useState(latestAssistant?.id ?? null);
    const showLatestAssistant = Boolean(
        latestAssistant &&
        (latestAssistant.id !== initialAssistantId ||
            (latestAssistant.created_at &&
                shoppingList?.generation_completed_at &&
                new Date(latestAssistant.created_at) >
                    new Date(shoppingList.generation_completed_at))),
    );
    const latestFailedMessage = messages.findLast(
        (message) =>
            message.role === 'user' &&
            message.response_status === 'failed' &&
            message.client_message_id,
    );

    return (
        <details className="group border-y py-4">
            <summary
                aria-label="Plan recap"
                className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-medium [&::-webkit-details-marker]:hidden"
            >
                <span className="flex items-center gap-2">
                    <MessageCircle className="size-4 text-primary" /> Plan recap
                </span>
                <ChevronDown className="size-4 text-muted-foreground transition-transform group-open:rotate-180" />
            </summary>
            <div className="mt-3 flex justify-end">
                <Button asChild size="sm" variant="ghost">
                    <Link href={`/meal-plans/${planId}`}>
                        View full conversation
                    </Link>
                </Button>
            </div>
            <p className="mt-3 max-w-[48em] text-sm leading-6 text-muted-foreground">
                {shoppingList === null
                    ? 'This plan is confirmed. Prepare the list when you are ready to review ingredients and pantry stock.'
                    : shoppingList.generation_status !== 'ready'
                      ? 'Chef is preparing the current plan into one structured shopping list.'
                      : `${shoppingList.items.filter((item) => item.included && !item.in_pantry).length} items are on the current list, ${shoppingList.items.filter((item) => item.in_pantry).length} are marked as already in the pantry, and ${shoppingList.items.filter((item) => item.included && !item.in_pantry && !item.checked && !item.ordered_at).length} remain to buy or order.`}
            </p>
            {showLatestAssistant && latestAssistant && (
                <div className="mt-3 max-w-[48em] border-l-2 pl-3 text-sm">
                    <p className="mb-1 text-xs font-medium text-muted-foreground">
                        Latest shopping update
                    </p>
                    <AssistantMessage content={latestAssistant.content} />
                </div>
            )}
            {latestFailedMessage && (
                <div className="mt-3 flex max-w-[48em] items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
                    <p className="text-xs text-destructive" role="status">
                        {latestFailedMessage.response_error ??
                            'Chef could not respond to the last shopping message.'}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        aria-label="Retry failed shopping message"
                        disabled={sending}
                        onClick={() => void retryMessage(latestFailedMessage)}
                    >
                        <RefreshCw /> Retry
                    </Button>
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
        </details>
    );
}

function updateShoppingItemState(
    item: ShoppingListItem,
    revision: number,
    changes: Record<string, boolean | string>,
    mergeSafe = false,
) {
    router.put(
        `/shopping-list-items/${item.id}`,
        {
            ...changes,
            ...(mergeSafe ? {} : { expected_revision: revision }),
        },
        { preserveScroll: true },
    );
}

function shoppingItemSourceMeals(item: ShoppingListItem) {
    return [
        ...new Set(
            item.sources
                .map((source) => source.planned_meal?.title)
                .filter((title): title is string => Boolean(title)),
        ),
    ];
}

function ShoppingItemActions({
    item,
    revision,
    shoppingCategories,
    stale,
}: {
    item: ShoppingListItem;
    revision: number;
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
    stale: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Actions for ${item.name}`}
                    disabled={stale}
                >
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    onSelect={() =>
                        updateShoppingItemState(item, revision, {
                            in_pantry: !item.in_pantry,
                        })
                    }
                >
                    <PackageCheck />
                    {item.in_pantry ? 'Move back to list' : 'Already in pantry'}
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        updateShoppingItemState(item, revision, {
                            included: !item.included,
                        })
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
                                    updateShoppingItemState(item, revision, {
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
    );
}

function ShoppingItemRow({
    item,
    revision,
    stale,
    shoppingCategories,
    pantryReview,
}: {
    item: ShoppingListItem;
    revision: number;
    stale: boolean;
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
    pantryReview: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const complete = item.checked || item.ordered_at !== null;
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
    const sourceMeals = shoppingItemSourceMeals(item);
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
                aria-label={
                    item.ordered_at
                        ? `${item.name} is ordered in the Woolworths cart`
                        : `Mark ${item.name} as ${item.checked ? 'not bought' : 'bought'}`
                }
                checked={complete}
                disabled={
                    stale ||
                    !item.included ||
                    item.in_pantry ||
                    item.ordered_at !== null
                }
                onCheckedChange={(checked) =>
                    updateShoppingItemState(
                        item,
                        revision,
                        { checked: Boolean(checked) },
                        true,
                    )
                }
            />
            <div className="min-w-0 py-1">
                {editing ? (
                    <form onSubmit={submitItem}>
                        <div className="grid grid-cols-[minmax(0,1fr)_4.5rem_4.5rem] gap-2">
                            <Input
                                aria-label={`${item.name} name`}
                                value={form.data.name}
                                disabled={stale}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                className={`h-9 border-transparent bg-transparent px-1 shadow-none hover:border-input focus-visible:border-input ${complete ? 'line-through' : ''}`}
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
                            {!['recipe', 'plan_generated'].includes(
                                item.source_kind,
                            ) && (
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
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setEditing(false)}
                            >
                                Done
                            </Button>
                        </div>
                    </form>
                ) : (
                    <div>
                        <div className="flex items-baseline justify-between gap-3">
                            <p
                                className={`min-w-0 truncate text-sm font-medium ${complete ? 'line-through' : ''}`}
                            >
                                {item.name}
                            </p>
                            <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                                {[item.quantity, item.unit]
                                    .filter(
                                        (value) =>
                                            value !== null && value !== '',
                                    )
                                    .join(' ') || '—'}
                            </span>
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            {sourceMeals.length > 0 && (
                                <span>For {sourceMeals.join(', ')}</span>
                            )}
                            {item.note && <span>{item.note}</span>}
                            {item.in_pantry && <span>Already in pantry</span>}
                            {item.ordered_at && <span>Ordered</span>}
                            {!item.included && <span>Excluded</span>}
                        </div>
                        {pantryReview && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="mt-2 min-h-9"
                                disabled={stale}
                                onClick={() =>
                                    updateShoppingItemState(item, revision, {
                                        in_pantry: true,
                                    })
                                }
                            >
                                <PackageCheck /> I have this
                            </Button>
                        )}
                    </div>
                )}
            </div>
            <div className="flex items-center">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={`Edit ${item.name}`}
                    disabled={stale}
                    onClick={() => setEditing((value) => !value)}
                >
                    <Pencil />
                </Button>
                <ShoppingItemActions
                    item={item}
                    revision={revision}
                    shoppingCategories={shoppingCategories}
                    stale={stale}
                />
            </div>
        </div>
    );
}

function ShoppingItemTableRow({
    item,
    revision,
    shoppingCategories,
    stale,
}: {
    item: ShoppingListItem;
    revision: number;
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
    stale: boolean;
}) {
    const sourceMeals = shoppingItemSourceMeals(item);
    const stateLabels = [
        item.in_pantry ? 'Pantry' : null,
        item.ordered_at ? 'Ordered' : null,
        !item.included ? 'Excluded' : null,
        item.optional ? 'Optional' : null,
    ].filter((label): label is string => label !== null);

    return (
        <TableRow
            className={!item.included || item.in_pantry ? 'opacity-60' : ''}
        >
            <TableCell className="w-12 min-w-12 p-1">
                <input
                    type="checkbox"
                    data-testid="shopping-item-checkbox"
                    className="m-2 size-5 min-h-5 min-w-5 shrink-0 accent-primary"
                    style={{ width: 20, minWidth: 20, height: 20 }}
                    aria-label={
                        item.ordered_at
                            ? `${item.name} is ordered in the Woolworths cart`
                            : `Mark ${item.name} as ${item.checked ? 'not bought' : 'bought'}`
                    }
                    checked={item.checked || item.ordered_at !== null}
                    disabled={
                        stale ||
                        !item.included ||
                        item.in_pantry ||
                        item.ordered_at !== null
                    }
                    onChange={(event) =>
                        updateShoppingItemState(
                            item,
                            revision,
                            { checked: event.currentTarget.checked },
                            true,
                        )
                    }
                />
            </TableCell>
            <TableCell className="w-[30%] py-2 whitespace-normal">
                <p
                    className={`font-medium ${item.checked || item.ordered_at ? 'line-through' : ''}`}
                >
                    {item.name}
                </p>
                {(item.note || stateLabels.length > 0) && (
                    <p className="mt-0.5 text-xs break-words text-muted-foreground">
                        {[item.note, ...stateLabels]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                )}
            </TableCell>
            <TableCell className="w-20 py-2 text-right tabular-nums">
                {item.quantity ?? '—'}
            </TableCell>
            <TableCell className="w-24 py-2">{item.unit ?? '—'}</TableCell>
            <TableCell className="w-[38%] py-2 text-xs whitespace-normal text-muted-foreground">
                {sourceMeals.length > 0 ? sourceMeals.join(', ') : '—'}
            </TableCell>
            <TableCell className="w-12 px-1 py-1 text-right">
                <ShoppingItemActions
                    item={item}
                    revision={revision}
                    shoppingCategories={shoppingCategories}
                    stale={stale}
                />
            </TableCell>
        </TableRow>
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

function ShoppingItemsSection({
    included,
    shoppingCategories,
    shoppingList,
}: {
    included: number;
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
    shoppingList: PreparedShoppingList;
}) {
    const [itemsView, setItemsView] = useState<ShoppingItemsView>(
        initialShoppingItemsView,
    );
    const [pantryReview, setPantryReview] = useState(false);
    const pantryCandidates = shoppingList.items.filter(
        (item) =>
            item.category === 'pantry' && item.included && !item.in_pantry,
    );
    const visibleItems = pantryReview ? pantryCandidates : shoppingList.items;
    const groupedItems: {
        value: ShoppingListItemCategory;
        label: string;
        items: ShoppingListItem[];
    }[] = [];

    for (const category of shoppingCategories) {
        const items = visibleItems.filter(
            (item) => item.category === category.value,
        );

        if (items.length > 0) {
            groupedItems.push({ ...category, items });
        }
    }

    const changeItemsView = (value: string) => {
        if (value !== 'list' && value !== 'table') {
            return;
        }

        setItemsView(value);

        try {
            window.localStorage.setItem(shoppingItemsViewStorageKey, value);
        } catch {
            // The selected view still applies for this visit.
        }
    };

    return (
        <details className="group mt-6 border-t pt-4">
            <summary
                aria-label="Shopping list items"
                className="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg px-1 py-2"
            >
                <span>
                    <span className="block text-sm font-medium">
                        Full shopping list
                    </span>
                    <span className="mt-1 block text-xs text-muted-foreground">
                        {included} items · Review pantry, quantities or add an
                        item
                    </span>
                </span>
                <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                    Show items
                    <ChevronDown className="size-4 transition-transform group-open:rotate-180" />
                </span>
            </summary>
            <section className="pt-4" aria-label="Shopping list editor">
                <div className="flex justify-end">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={itemsView}
                        onValueChange={changeItemsView}
                        aria-label="Shopping items view"
                    >
                        <ToggleGroupItem
                            value="table"
                            aria-label="Table view"
                            className="px-2.5"
                        >
                            <Table2 /> Table
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="list"
                            aria-label="List view"
                            className="px-2.5"
                        >
                            <ListIcon /> List
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                {pantryCandidates.length > 0 && (
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-3">
                        <div>
                            <p className="text-sm font-medium">Pantry review</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Optional: review {pantryCandidates.length}{' '}
                                {pantryCandidates.length === 1
                                    ? 'staple'
                                    : 'staples'}{' '}
                                to avoid ordering things you already have.
                                Otherwise Chef will order everything on the
                                list.
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant={pantryReview ? 'secondary' : 'outline'}
                            onClick={() => {
                                setPantryReview((value) => !value);
                                setItemsView('list');
                            }}
                        >
                            <PackageCheck />
                            {pantryReview ? 'Show all items' : 'Review pantry'}
                        </Button>
                    </div>
                )}
                {itemsView === 'list' && (
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
                                            stale={
                                                shoppingList.stale_at !== null
                                            }
                                            shoppingCategories={
                                                shoppingCategories
                                            }
                                            pantryReview={pantryReview}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
                {itemsView === 'table' && shoppingList.items.length > 0 && (
                    <div className="mt-5 overflow-x-auto border-y">
                        <Table className="min-w-[44rem] table-fixed">
                            <colgroup>
                                <col className="w-12" />
                                <col className="w-[30%]" />
                                <col className="w-20" />
                                <col className="w-24" />
                                <col />
                                <col className="w-14" />
                            </colgroup>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <span className="sr-only">Bought</span>
                                    </TableHead>
                                    <TableHead className="w-[30%]">
                                        Item
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Qty
                                    </TableHead>
                                    <TableHead>Unit</TableHead>
                                    <TableHead className="w-[38%]">
                                        Used for
                                    </TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            {groupedItems.map((category) => (
                                <TableBody key={category.value}>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableCell
                                            colSpan={6}
                                            className="px-2 py-2"
                                        >
                                            <div className="flex items-baseline justify-between gap-3">
                                                <span className="font-medium">
                                                    {category.label}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {category.items.length}{' '}
                                                    {category.items.length === 1
                                                        ? 'item'
                                                        : 'items'}
                                                </span>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                    {category.items.map((item) => (
                                        <ShoppingItemTableRow
                                            key={item.id}
                                            item={item}
                                            revision={shoppingList.revision}
                                            stale={
                                                shoppingList.stale_at !== null
                                            }
                                            shoppingCategories={
                                                shoppingCategories
                                            }
                                        />
                                    ))}
                                </TableBody>
                            ))}
                        </Table>
                    </div>
                )}
                {shoppingList.items.length === 0 && (
                    <p className="mt-5 px-1 py-8 text-sm text-muted-foreground">
                        This plan does not need any groceries yet. Add a
                        household item or return to the plan to review its
                        meals.
                    </p>
                )}
                {!shoppingList.stale_at && (
                    <AddItemForm
                        listId={shoppingList.id}
                        revision={shoppingList.revision}
                    />
                )}
            </section>
        </details>
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
    cartSnapshot,
}: {
    listId: number;
    retailers: { id: number; name: string }[];
    orders: NonNullable<ShoppingWorkspace['shopping_list']>['orders'];
    cartSnapshot: AutomationRun['snapshot'] | null;
}) {
    const form = useForm({
        actual_total: cartSnapshot?.cart_total?.toString() ?? '',
        retailer_id: '',
        cart_snapshot_id: cartSnapshot?.id ?? null,
    });

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
                        actual_total: data.actual_total
                            ? Number(data.actual_total)
                            : null,
                        retailer_id: data.retailer_id
                            ? Number(data.retailer_id)
                            : null,
                        cart_snapshot_id: data.cart_snapshot_id,
                    }));
                    form.post(`/shopping-lists/${listId}/orders`, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                {!cartSnapshot && (
                    <Select
                        value={form.data.retailer_id}
                        onValueChange={(value) =>
                            form.setData('retailer_id', value)
                        }
                    >
                        <SelectTrigger
                            aria-label="Order retailer"
                            className="w-40"
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
                )}
                <Input
                    aria-label="Actual order total"
                    className="w-36"
                    type="number"
                    min="0"
                    step="0.01"
                    required={!cartSnapshot}
                    placeholder={
                        cartSnapshot ? 'Cart total (optional)' : 'Actual total'
                    }
                    value={form.data.actual_total}
                    onChange={(event) =>
                        form.setData('actual_total', event.target.value)
                    }
                />
                <Button size="sm" disabled={form.processing}>
                    {cartSnapshot ? 'Save cart snapshot' : 'Record order'}
                </Button>
            </form>
            {cartSnapshot && (
                <p className="mt-2 text-xs text-muted-foreground">
                    Chef prefilled the verified Woolworths cart total and
                    products captured{' '}
                    {new Date(cartSnapshot.captured_at).toLocaleString('en-AU')}
                    .
                </p>
            )}
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

const activeAutomationStatuses = new Set([
    'checking_connection',
    'inspecting_existing_cart',
    'queued',
    'running',
    'reconciling',
]);
const terminalAutomationStatuses = new Set([
    'ready_for_review',
    'superseded',
    'cancelled',
    'failed',
    'expired',
]);

function automationStatusLabel(status: string) {
    return status.replaceAll('_', ' ');
}

function snapshotLineLabel(line: CartSnapshotLine) {
    return line.classification.replaceAll('_', ' ');
}

type AutomationResolutionChoice =
    | 'merge'
    | 'replace'
    | 'cancel'
    | 'retry'
    | 'skip'
    | 'accept_substitution'
    | 'accept_product';

function AutomationInterventionCard({
    intervention,
    processing,
    onReauthenticate,
    onResolve,
}: {
    intervention: NonNullable<AutomationRun['intervention']>;
    processing: boolean;
    onReauthenticate: () => void;
    onResolve: (choice: AutomationResolutionChoice) => void;
}) {
    return (
        <div className="mt-4 rounded-lg bg-muted/50 p-3">
            <p className="text-sm font-medium">
                {intervention.payload?.message ??
                    'Chef needs a decision before continuing.'}
            </p>
            {intervention.type === 'existing_cart' &&
                intervention.payload?.lines && (
                    <ul className="mt-2 space-y-1 text-xs text-muted-foreground">
                        {intervention.payload.lines.map((line) => (
                            <li
                                key={
                                    line.external_product_id ??
                                    `${line.product_name}-${line.unit ?? ''}`
                                }
                            >
                                {line.product_name}
                                {line.quantity ? ` × ${line.quantity}` : ''}
                            </li>
                        ))}
                    </ul>
                )}
            <div className="mt-3 flex flex-wrap gap-2">
                {(intervention.type === 'existing_cart' ||
                    (intervention.type === 'cart_changed' &&
                        intervention.automation_run_item_id === null)) && (
                    <>
                        <Button
                            size="sm"
                            disabled={processing}
                            onClick={() => onResolve('merge')}
                        >
                            Merge carts
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() => onResolve('replace')}
                        >
                            Replace existing cart
                        </Button>
                    </>
                )}
                {intervention.type === 'reauthentication' && (
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={onReauthenticate}
                    >
                        <LogIn /> Reauthenticate
                    </Button>
                )}
                {intervention.type === 'manual_takeover' &&
                    intervention.takeover_url && (
                        <Button asChild size="sm">
                            <Link href={intervention.takeover_url}>
                                <Hand /> Continue manual control
                            </Link>
                        </Button>
                    )}
                {[
                    'item_decision',
                    'price_limit',
                    'substitution',
                    'cart_changed',
                ].includes(intervention.type) &&
                    intervention.automation_run_item_id !== null && (
                        <>
                            {intervention.type === 'price_limit' && (
                                <Button
                                    size="sm"
                                    disabled={processing}
                                    onClick={() => onResolve('accept_product')}
                                >
                                    Accept price
                                </Button>
                            )}
                            {intervention.type === 'substitution' && (
                                <Button
                                    size="sm"
                                    disabled={processing}
                                    onClick={() =>
                                        onResolve('accept_substitution')
                                    }
                                >
                                    Accept substitution
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                onClick={() => onResolve('retry')}
                            >
                                Retry item
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                                onClick={() => onResolve('skip')}
                            >
                                Skip item
                            </Button>
                        </>
                    )}
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    onClick={() => onResolve('cancel')}
                >
                    Cancel run
                </Button>
            </div>
        </div>
    );
}

function CartSnapshotReview({
    run,
    shoppingList,
}: {
    run: AutomationRun;
    shoppingList: PreparedShoppingList;
}) {
    const fulfilmentForm = useForm({
        fulfilment_method: shoppingList.fulfilment_method,
    });

    if (!run.snapshot) {
        return null;
    }

    const chooseFulfilment = (method: 'delivery' | 'pickup') => {
        fulfilmentForm.setData('fulfilment_method', method);
        fulfilmentForm.transform(() => ({ fulfilment_method: method }));
        fulfilmentForm.put(`/shopping-lists/${shoppingList.id}/fulfilment`, {
            preserveScroll: true,
        });
    };

    return (
        <div className="mt-5 border-t pt-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-medium">Your cart is ready</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Chef items{' '}
                        {formatMoney(Number(run.snapshot.chef_subtotal ?? 0))} ·
                        whole cart{' '}
                        {formatMoney(Number(run.snapshot.cart_total ?? 0))}
                    </p>
                </div>
            </div>
            <div className="mt-4 rounded-xl bg-primary/5 p-3">
                <p className="text-sm font-medium">
                    How would you like to receive the shop?
                </p>
                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    Choose delivery or pickup here, then select an available
                    time and complete checkout securely in Woolworths.
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant={
                            shoppingList.fulfilment_method === 'delivery'
                                ? 'default'
                                : 'outline'
                        }
                        disabled={fulfilmentForm.processing}
                        onClick={() => chooseFulfilment('delivery')}
                    >
                        Delivery
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={
                            shoppingList.fulfilment_method === 'pickup'
                                ? 'default'
                                : 'outline'
                        }
                        disabled={fulfilmentForm.processing}
                        onClick={() => chooseFulfilment('pickup')}
                    >
                        Pickup
                    </Button>
                </div>
                {run.can_open_woolworths_cart &&
                    run.open_woolworths_cart_url &&
                    shoppingList.fulfilment_method && (
                        <Button asChild className="mt-3" size="sm">
                            <a
                                href={run.open_woolworths_cart_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Choose a {shoppingList.fulfilment_method} time
                                &amp; checkout <ExternalLink />
                            </a>
                        </Button>
                    )}
            </div>
            <details className="mt-4 text-xs">
                <summary className="cursor-pointer text-muted-foreground">
                    Review cart products
                </summary>
                <ul className="mt-2 divide-y">
                    {run.snapshot.lines.map((line) => (
                        <li
                            key={line.id}
                            className="flex items-start justify-between gap-3 py-2"
                        >
                            <span>{line.product_name}</span>
                            <span className="text-right text-muted-foreground capitalize">
                                {snapshotLineLabel(line)}
                                {line.total_price !== null
                                    ? ` · ${formatMoney(Number(line.total_price))}`
                                    : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            </details>
            {!run.normal_app_sync_proven && (
                <p className="mt-3 text-xs text-amber-700 dark:text-amber-300">
                    The normal-app cart synchronisation release trial is not yet
                    recorded as proven. Do not treat this run as M6 release
                    evidence.
                </p>
            )}
        </div>
    );
}

function CartAutomationRunPanel({
    intervention,
    processing,
    run,
    runTerminal,
    onCancel,
    onReauthenticate,
    onResolve,
    onTakeover,
    shoppingList,
}: {
    intervention: AutomationRun['intervention'] | undefined;
    processing: boolean;
    run: AutomationRun;
    runTerminal: boolean;
    onCancel: () => void;
    onReauthenticate: () => void;
    onResolve: (choice: AutomationResolutionChoice) => void;
    onTakeover: () => void;
    shoppingList: PreparedShoppingList;
}) {
    if (runTerminal && run.status !== 'ready_for_review') {
        return (
            <details className="mt-5 rounded-xl border bg-background p-4">
                <summary className="cursor-pointer list-none text-sm font-medium capitalize">
                    Previous cart attempt: {automationStatusLabel(run.status)}
                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                        {run.progress.resolved} of {run.progress.total} items
                    </span>
                </summary>
                <p className="mt-2 text-xs text-muted-foreground">
                    Frozen at shopping-list revision{' '}
                    {run.shopping_list_revision}. This history does not block a
                    new product plan.
                </p>
                {run.failure_message && (
                    <p className="mt-2 text-xs text-destructive">
                        {run.failure_message}
                    </p>
                )}
            </details>
        );
    }

    return (
        <div className="mt-5 rounded-xl border bg-background p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-medium capitalize">
                            {automationStatusLabel(run.status)}
                        </p>
                        <Badge variant="secondary">
                            {run.progress.resolved} of {run.progress.total}{' '}
                            items
                        </Badge>
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Frozen at shopping-list revision{' '}
                        {run.shopping_list_revision}
                    </p>
                </div>
                {!runTerminal && !intervention && (
                    <div className="flex flex-wrap gap-2">
                        {activeAutomationStatuses.has(run.status) && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                onClick={onTakeover}
                            >
                                <Hand /> Pause and take over
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={onCancel}
                        >
                            Cancel run
                        </Button>
                    </div>
                )}
            </div>

            {activeAutomationStatuses.has(run.status) && (
                <div className="mt-4" role="status">
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full w-full origin-left rounded-full bg-primary transition-transform"
                            style={{
                                transform: `scaleX(${run.progress.total === 0 ? 0 : run.progress.resolved / run.progress.total})`,
                            }}
                        />
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Chef verifies the remote cart after every item.
                    </p>
                </div>
            )}

            {run.revision_diverged && !runTerminal && (
                <div className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                    The shopping list changed after this run was approved.
                    Cancel this frozen run and prepare a new one from revision{' '}
                    {run.current_shopping_list_revision}.
                </div>
            )}

            {intervention && (
                <AutomationInterventionCard
                    intervention={intervention}
                    processing={processing}
                    onReauthenticate={onReauthenticate}
                    onResolve={onResolve}
                />
            )}

            {run.failure_message && (
                <p className="mt-4 text-xs text-destructive">
                    {run.failure_message}
                </p>
            )}

            {run.items.length > 0 && !run.snapshot && (
                <details className="mt-4 border-t pt-3 text-xs">
                    <summary className="cursor-pointer text-muted-foreground">
                        View item progress
                    </summary>
                    <ul className="mt-2 divide-y">
                        {run.items.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-center justify-between gap-3 py-2"
                            >
                                <span>{item.name}</span>
                                <span className="text-muted-foreground capitalize">
                                    {automationStatusLabel(item.status)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            <CartSnapshotReview run={run} shoppingList={shoppingList} />
        </div>
    );
}

function ExactProductMatchEditor({
    focusedItemId,
    shoppingList,
    retailers,
}: {
    focusedItemId?: number | null;
    shoppingList: PreparedShoppingList;
    retailers: ShoppingWorkspace['retailers'];
}) {
    const woolworths = retailers.find(
        (retailer) => retailer.slug === 'woolworths',
    );
    const unmatchedItems = shoppingList.items.filter(
        (item) =>
            item.included &&
            !item.in_pantry &&
            (focusedItemId === undefined ||
                focusedItemId === null ||
                item.id === focusedItemId) &&
            (!item.product_match?.retail_product.external_id ||
                !item.product_match.retail_product.product_url),
    );
    const [itemId, setItemId] = useState(
        () => unmatchedItems[0]?.id.toString() ?? '',
    );
    const selectedItemId = unmatchedItems.some(
        (item) => item.id.toString() === itemId,
    )
        ? itemId
        : (unmatchedItems[0]?.id.toString() ?? '');
    const form = useForm({
        name: '',
        product_url: '',
        brand: '',
        pack_quantity: '',
        pack_unit: '',
        price: '',
        pack_count: '1',
        maximum_price: '',
    });

    if (!woolworths || unmatchedItems.length === 0) {
        return null;
    }

    return (
        <details className="mt-3 rounded-lg border bg-background p-3">
            <summary className="cursor-pointer text-xs font-medium">
                Add an exact Woolworths product
            </summary>
            <form
                className="mt-3 grid gap-2 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    const selectedItem = unmatchedItems.find(
                        (item) => item.id.toString() === selectedItemId,
                    );

                    if (!selectedItem) {
                        return;
                    }

                    form.transform((data) => ({
                        retailer_id: woolworths.id,
                        name: data.name,
                        external_id: null,
                        product_url: data.product_url,
                        brand: data.brand.trim() || null,
                        pack_quantity: data.pack_quantity
                            ? Number(data.pack_quantity)
                            : null,
                        pack_unit: data.pack_unit.trim() || null,
                        price: Number(data.price),
                        pack_count: Number(data.pack_count),
                        preferred: true,
                        accept_substitutes: false,
                        maximum_price: data.maximum_price
                            ? Number(data.maximum_price)
                            : null,
                        note: 'Exact product approved for the current household safety context.',
                        expected_revision: shoppingList.revision,
                    }));
                    form.put(
                        `/shopping-list-items/${selectedItem.id}/product-match`,
                        { preserveScroll: true },
                    );
                }}
            >
                {unmatchedItems.length > 1 ? (
                    <label className="grid gap-1 text-xs">
                        Shopping item
                        <select
                            className="h-9 rounded-md border bg-background px-2"
                            value={selectedItemId}
                            onChange={(event) => setItemId(event.target.value)}
                        >
                            {unmatchedItems.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : (
                    <p className="text-xs font-medium">
                        {unmatchedItems[0]?.name}
                    </p>
                )}
                <label className="grid gap-1 text-xs">
                    Exact product name
                    <Input
                        required
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Woolworths product URL
                    <Input
                        required
                        type="url"
                        placeholder="https://www.woolworths.com.au/shop/productdetails/…"
                        value={form.data.product_url}
                        onChange={(event) =>
                            form.setData('product_url', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Brand
                    <Input
                        value={form.data.brand}
                        onChange={(event) =>
                            form.setData('brand', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Price
                    <Input
                        required
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.price}
                        onChange={(event) =>
                            form.setData('price', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Pack size
                    <div className="grid grid-cols-2 gap-2">
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            placeholder="Quantity"
                            value={form.data.pack_quantity}
                            onChange={(event) =>
                                form.setData(
                                    'pack_quantity',
                                    event.target.value,
                                )
                            }
                        />
                        <Input
                            placeholder="Unit"
                            value={form.data.pack_unit}
                            onChange={(event) =>
                                form.setData('pack_unit', event.target.value)
                            }
                        />
                    </div>
                </label>
                <label className="grid gap-1 text-xs">
                    Packs to add
                    <Input
                        required
                        type="number"
                        min="1"
                        step="1"
                        value={form.data.pack_count}
                        onChange={(event) =>
                            form.setData('pack_count', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs">
                    Maximum accepted price
                    <Input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.maximum_price}
                        onChange={(event) =>
                            form.setData('maximum_price', event.target.value)
                        }
                    />
                </label>
                <div className="flex items-end">
                    <Button size="sm" disabled={form.processing}>
                        Save exact match
                    </Button>
                </div>
            </form>
        </details>
    );
}

function CartProductPreflight({
    approved,
    cartMutationEnabled,
    onBuildPlan,
    onPrepare,
    onProductPlanReviewedChange,
    onSelectCandidate,
    onSafetyAcknowledgedChange,
    preflight,
    processing,
    productPlan,
    productPlanReviewed,
    ready,
    retailers,
    safetyAcknowledged,
    shoppingList,
}: {
    approved: boolean;
    cartMutationEnabled: boolean;
    onBuildPlan: () => void;
    onPrepare: () => void;
    onProductPlanReviewedChange: (checked: boolean) => void;
    onSelectCandidate: (itemId: number, candidateIndex: number) => void;
    onSafetyAcknowledgedChange: (checked: boolean) => void;
    preflight: CartAutomation['preflight'];
    processing: boolean;
    productPlan: CartAutomation['product_plan'];
    productPlanReviewed: boolean;
    ready: boolean;
    retailers: ShoppingWorkspace['retailers'];
    safetyAcknowledged: boolean;
    shoppingList: PreparedShoppingList;
}) {
    const pendingItems =
        productPlan?.items.filter((item) => item.status !== 'exact') ?? [];
    const currentItem = pendingItems[0] ?? null;
    const planReady = productPlan?.status === 'ready';
    const nextStep = !productPlan
        ? 'Find products for your list'
        : productPlan.discovery_failed
          ? 'Retry Woolworths product matching'
          : planReady
            ? 'Prepare your Woolworths cart'
            : currentItem
              ? `Choose a product for ${currentItem.name}`
              : 'Review product choices';

    return (
        <div
            className="mb-5 rounded-xl border border-primary/20 bg-primary/5 p-5"
            data-testid="shopping-next-step"
        >
            <p className="text-[11px] font-medium tracking-wide text-primary uppercase">
                Next step
            </p>
            <p className="mt-1 text-base font-semibold">{nextStep}</p>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                {!productPlan
                    ? `Chef will search Woolworths for ${preflight.total_items} items before opening your authenticated cart.`
                    : productPlan.discovery_failed
                      ? 'Woolworths did not return usable catalogue results. No cart changes were attempted.'
                      : planReady
                        ? `All ${productPlan.exact_items} products are matched. Chef can now prepare and verify the cart for you.`
                        : `${productPlan.exact_items} products were matched automatically. Choose the best option below so Chef can continue; ${pendingItems.length} ${pendingItems.length === 1 ? 'decision remains' : 'decisions remain'}.`}
            </p>
            {preflight.constraints.length > 0 && (
                <div className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                    <p className="font-medium">Household safety constraints</p>
                    <ul className="mt-1 space-y-1">
                        {preflight.constraints.map((constraint) => (
                            <li key={constraint.id}>
                                {constraint.person
                                    ? `${constraint.person}: `
                                    : ''}
                                {constraint.kind} — {constraint.subject}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {(!productPlan || productPlan.discovery_failed) && (
                <div className="mt-3">
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={onBuildPlan}
                    >
                        <RefreshCw />
                        {productPlan?.discovery_failed
                            ? 'Try product search again'
                            : 'Find Woolworths products'}
                    </Button>
                    {productPlan?.discovery_failed && (
                        <p
                            className="mt-2 text-xs text-destructive"
                            role="alert"
                        >
                            If this keeps failing, leave the cart untouched and
                            try again later. You do not need to match every item
                            manually.
                        </p>
                    )}
                </div>
            )}
            {currentItem && !productPlan?.discovery_failed && (
                <div className="mt-4 rounded-lg border bg-background p-3">
                    <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                        Choose one · {pendingItems.length} remaining
                    </p>
                    {currentItem.candidates.length > 0 ? (
                        <div className="mt-3 grid gap-2">
                            {currentItem.candidates.map((candidate, index) => (
                                <Button
                                    key={`${currentItem.id}-${String(candidate.external_id ?? index)}`}
                                    aria-label={`Choose ${String(candidate.product_name ?? 'Woolworths product')} for ${currentItem.name}`}
                                    size="sm"
                                    variant="outline"
                                    className="h-auto justify-start px-3 py-2 text-left whitespace-normal"
                                    disabled={processing}
                                    onClick={() =>
                                        onSelectCandidate(currentItem.id, index)
                                    }
                                >
                                    <span>
                                        {String(
                                            candidate.product_name ??
                                                'Woolworths product',
                                        )}
                                        {candidate.current_price !==
                                            undefined ||
                                        candidate.price !== undefined
                                            ? ` · ${formatMoney(Number(candidate.current_price ?? candidate.price))}`
                                            : ''}
                                        {candidate.pack_size
                                            ? ` · ${String(candidate.pack_size)}`
                                            : ''}
                                    </span>
                                </Button>
                            ))}
                        </div>
                    ) : (
                        <>
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                Chef could not find a safe catalogue match for
                                this item. Add one exact Woolworths product, or
                                adjust the shopping item and search again.
                            </p>
                            <ExactProductMatchEditor
                                focusedItemId={
                                    currentItem.shopping_list_item_id
                                }
                                shoppingList={shoppingList}
                                retailers={retailers}
                            />
                        </>
                    )}
                    {pendingItems.length > 1 && (
                        <details className="mt-3 text-xs text-muted-foreground">
                            <summary className="cursor-pointer">
                                View the other {pendingItems.length - 1}{' '}
                                remaining items
                            </summary>
                            <ul className="mt-2 columns-1 space-y-1 gap-x-6 sm:columns-2">
                                {pendingItems.slice(1).map((item) => (
                                    <li key={item.id}>{item.name}</li>
                                ))}
                            </ul>
                        </details>
                    )}
                </div>
            )}
            {planReady && productPlan && (
                <>
                    {approved ? (
                        <p className="mt-3 rounded-lg bg-primary/5 px-3 py-2 text-xs leading-5 text-muted-foreground">
                            These routine matches are inside the approved plan.
                            Chef will continue automatically and pause if the
                            retailer presents a material difference.
                        </p>
                    ) : (
                        <label className="mt-3 flex items-start gap-2 text-xs leading-5">
                            <Checkbox
                                aria-label="Confirm exact Woolworths product plan review"
                                checked={productPlanReviewed}
                                onCheckedChange={(checked) =>
                                    onProductPlanReviewedChange(
                                        Boolean(checked),
                                    )
                                }
                            />
                            <span>
                                I reviewed the exact Woolworths products that
                                Chef will add and verify.
                            </span>
                        </label>
                    )}
                    <details className="mt-3 text-xs text-muted-foreground">
                        <summary className="cursor-pointer">
                            Exact products
                        </summary>
                        <ul className="mt-2 space-y-1">
                            {productPlan.items.map((item) => (
                                <li key={item.id}>
                                    {item.name} —{' '}
                                    {String(
                                        item.selected_product?.product_name ??
                                            'Exact product',
                                    )}
                                </li>
                            ))}
                        </ul>
                    </details>
                    {!approved && (
                        <label className="mt-3 flex items-start gap-2 text-xs leading-5">
                            <Checkbox
                                aria-label="Confirm cart product plan safety review"
                                checked={safetyAcknowledged}
                                onCheckedChange={(checked) =>
                                    onSafetyAcknowledgedChange(Boolean(checked))
                                }
                            />
                            <span>
                                I reviewed the household safety context and this
                                product plan.
                            </span>
                        </label>
                    )}
                    <Button
                        className="mt-4"
                        disabled={
                            processing ||
                            !ready ||
                            !cartMutationEnabled ||
                            (!approved &&
                                (!safetyAcknowledged || !productPlanReviewed))
                        }
                        onClick={onPrepare}
                    >
                        <ShoppingBasket /> Prepare Woolworths cart
                    </Button>
                    {!cartMutationEnabled && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Cart preparation is disabled until the configured
                            provider and live release gates are ready.
                        </p>
                    )}
                </>
            )}
        </div>
    );
}

function CartAutomationSection({
    automation,
    shoppingList,
    retailers,
}: {
    automation: CartAutomation;
    shoppingList: PreparedShoppingList;
    retailers: ShoppingWorkspace['retailers'];
}) {
    const [processing, setProcessing] = useState(false);
    const [safetyAcknowledged, setSafetyAcknowledged] = useState(false);
    const [productPlanReviewed, setProductPlanReviewed] = useState(false);
    const connection = automation.connection;
    const run = automation.run;
    const intervention = run?.intervention;
    const runTerminal = run ? terminalAutomationStatuses.has(run.status) : true;
    const submit = (
        method: 'post' | 'put' | 'delete',
        url: string,
        data: RequestPayload = {},
    ) => {
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        };

        if (method === 'delete') {
            router.delete(url, { ...options, data });

            return;
        }

        if (method === 'put') {
            router.put(url, data, options);

            return;
        }

        router.post(url, data, options);
    };
    const connect = () =>
        submit(
            'post',
            `/shopping-lists/${shoppingList.id}/retailer-connections`,
        );
    const reauthenticate = () => {
        if (connection) {
            submit(
                'post',
                `/retailer-connections/${connection.id}/authenticate`,
            );
        }
    };
    const prepare = () => {
        if (!connection || !automation.shopping_list_revision_id) {
            return;
        }

        submit('post', `/shopping-lists/${shoppingList.id}/automation-runs`, {
            shopping_list_revision_id: automation.shopping_list_revision_id,
            retailer_connection_id: connection.id,
            idempotency_key: crypto.randomUUID(),
            safety_acknowledged: automation.approved || safetyAcknowledged,
            product_plan_reviewed: automation.approved || productPlanReviewed,
        });
    };
    const buildProductPlan = () => {
        if (!connection || !automation.shopping_list_revision_id) {
            return;
        }

        setProductPlanReviewed(false);
        submit('post', `/shopping-lists/${shoppingList.id}/cart-product-plan`, {
            shopping_list_revision_id: automation.shopping_list_revision_id,
            retailer_connection_id: connection.id,
        });
    };
    const selectProductCandidate = (itemId: number, candidateIndex: number) => {
        setProductPlanReviewed(false);
        submit('put', `/cart-product-plan-items/${itemId}`, {
            candidate_index: candidateIndex,
        });
    };
    const cancel = () => {
        if (run) {
            submit('delete', `/automation-runs/${run.id}`);
        }
    };
    const takeover = () => {
        if (run) {
            submit('post', `/automation-runs/${run.id}/takeover`);
        }
    };
    const resolve = (choice: AutomationResolutionChoice) => {
        if (!intervention) {
            return;
        }

        submit('put', `/automation-interventions/${intervention.id}`, {
            choice,
        });
    };

    if (!automation.connection_enabled) {
        return null;
    }

    return (
        <section className="mt-8" aria-labelledby="woolworths-cart-heading">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="max-w-2xl">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <ShieldCheck className="size-4 text-primary" />
                        <span id="woolworths-cart-heading">
                            Woolworths cart
                        </span>
                        {connection?.status === 'connected' && (
                            <Badge variant="secondary">Connected</Badge>
                        )}
                    </p>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        {connection?.status === 'connected'
                            ? `${connection.last_verified_at ? `Verified ${new Date(connection.last_verified_at).toLocaleString('en-AU')}. ` : ''}Resolve only the exceptions below; Chef will handle the routine cart work.`
                            : 'Chef can prepare and verify this revision in your Woolworths account. You review the result and complete checkout in your normal Woolworths app or browser.'}
                    </p>
                </div>
                {connection && connection.status !== 'disconnected' && (
                    <Button
                        className="self-start sm:self-auto"
                        size="sm"
                        variant="ghost"
                        disabled={processing || (run !== null && !runTerminal)}
                        onClick={() => {
                            if (
                                window.confirm(
                                    'Disconnect Woolworths and delete its saved browser context?',
                                )
                            ) {
                                submit(
                                    'delete',
                                    `/retailer-connections/${connection.id}`,
                                );
                            }
                        }}
                    >
                        <Unplug /> Disconnect
                    </Button>
                )}
            </div>

            {!connection || connection.status === 'disconnected' ? (
                <div className="mt-5 rounded-xl bg-muted/40 p-4">
                    <p className="text-sm font-medium">
                        Connect Woolworths when the list is ready
                    </p>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        A private, recording-disabled browser opens for you to
                        enter your password and MFA. Chef&rsquo;s model is not
                        attached during sign-in.
                    </p>
                    <Button
                        className="mt-4"
                        size="sm"
                        disabled={processing || !automation.ready}
                        onClick={connect}
                    >
                        <LogIn /> Connect Woolworths
                    </Button>
                    {!automation.ready && (
                        <ul className="mt-3 space-y-1 text-xs text-muted-foreground">
                            {automation.readiness_reasons.map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : connection.status !== 'connected' ? (
                <div className="mt-5 rounded-xl bg-muted/40 p-4">
                    <p className="text-sm font-medium">
                        {connection.status === 'pending_login' ||
                        connection.status === 'checking'
                            ? 'Finish the secure Woolworths sign-in'
                            : 'Woolworths needs to be reconnected'}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Cart automation remains stopped until a deterministic
                        protected-page check passes.
                    </p>
                    <Button
                        className="mt-4"
                        size="sm"
                        disabled={processing}
                        onClick={reauthenticate}
                    >
                        <LogIn /> Open secure sign-in
                    </Button>
                </div>
            ) : (
                <div className="mt-5">
                    {(!run || runTerminal) && (
                        <CartProductPreflight
                            approved={automation.approved}
                            cartMutationEnabled={
                                automation.cart_mutation_enabled
                            }
                            onBuildPlan={buildProductPlan}
                            onPrepare={prepare}
                            onProductPlanReviewedChange={setProductPlanReviewed}
                            onSelectCandidate={selectProductCandidate}
                            onSafetyAcknowledgedChange={setSafetyAcknowledged}
                            preflight={automation.preflight}
                            processing={processing}
                            productPlan={automation.product_plan}
                            productPlanReviewed={productPlanReviewed}
                            ready={automation.ready}
                            retailers={retailers}
                            safetyAcknowledged={safetyAcknowledged}
                            shoppingList={shoppingList}
                        />
                    )}
                </div>
            )}

            {run && (
                <CartAutomationRunPanel
                    intervention={intervention}
                    processing={processing}
                    run={run}
                    runTerminal={runTerminal}
                    onCancel={cancel}
                    onReauthenticate={reauthenticate}
                    onResolve={resolve}
                    onTakeover={takeover}
                    shoppingList={shoppingList}
                />
            )}
        </section>
    );
}

type PreparedShoppingList = NonNullable<ShoppingWorkspace['shopping_list']>;
type MissingMeal = ShoppingWorkspace['missing_meals'][number];
type ShoppingItemsView = 'list' | 'table';

const shoppingItemsViewStorageKey = 'chef.shopping.items-view';

function initialShoppingItemsView(): ShoppingItemsView {
    if (typeof window === 'undefined') {
        return 'list';
    }

    try {
        if (window.matchMedia('(max-width: 767px)').matches) {
            return 'list';
        }

        return window.localStorage.getItem(shoppingItemsViewStorageKey) ===
            'table'
            ? 'table'
            : 'list';
    } catch {
        return 'list';
    }
}

function shoppingCompletionDisabledReason(
    shoppingList: PreparedShoppingList,
    remaining: number,
    missingMeals: MissingMeal[],
    processing: boolean,
): string | null {
    if (processing) {
        return 'Chef is completing this shop now.';
    }

    if (shoppingList.generation_status !== 'ready') {
        return 'Wait for Chef to finish preparing the shopping list.';
    }

    if (shoppingList.stale_at !== null) {
        return 'Review the latest plan changes and refresh this shopping list first.';
    }

    if (shoppingList.items.length === 0) {
        return 'Add at least one item before completing this shop.';
    }

    if (missingMeals.length > 0) {
        return 'Resolve the meals with missing ingredients before completing this shop.';
    }

    if (remaining > 0) {
        return `Buy or order the ${remaining} remaining ${remaining === 1 ? 'item' : 'items'} to complete this shop.`;
    }

    return null;
}

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

function SafetyReviewRequired({ planId }: { planId: number }) {
    return (
        <section className="py-12">
            <div className="mx-auto max-w-lg rounded-xl bg-amber-500/5 px-5 py-4 text-center">
                <ShieldCheck className="mx-auto size-6 text-amber-700 dark:text-amber-300" />
                <p className="mt-3 text-sm font-medium">
                    Review the plan&rsquo;s safety details
                </p>
                <p className="mt-1 text-sm text-muted-foreground">
                    Participants or household safety constraints changed after
                    this plan was confirmed. Review them and explicitly
                    reconfirm the plan before preparing this list.
                </p>
                <Button asChild className="mt-4" size="sm">
                    <Link href={`/meal-plans/${planId}`}>
                        <ShieldCheck /> Review plan safety
                    </Link>
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
    failedMeals,
    onPrepare,
    recipesPreparing,
    recipePreparation,
    shoppingList,
}: {
    failedMeals: MissingMeal[];
    onPrepare: () => void;
    recipesPreparing: boolean;
    recipePreparation: ShoppingWorkspace['recipe_preparation'];
    shoppingList: PreparedShoppingList;
}) {
    const generationFailed =
        shoppingList.generation_status === 'failed' ||
        shoppingList.generation_failure_code === 'context_changed';

    return (
        <section className="py-12">
            {generationFailed ? (
                <div className="mx-auto max-w-lg rounded-xl bg-destructive/5 px-5 py-4 text-center">
                    <p className="flex items-center justify-center gap-2 text-sm font-medium">
                        <CircleAlert className="size-4" /> Chef could not finish
                        this shopping list
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {shoppingList.generation_failure_message ??
                            'The list is safe to retry.'}
                    </p>
                    <Button className="mt-4" size="sm" onClick={onPrepare}>
                        <RefreshCw /> Retry
                    </Button>
                </div>
            ) : recipePreparation.failed > 0 ? (
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
                        {recipesPreparing
                            ? 'Preparing your recipes'
                            : shoppingList.generation_status === 'pending'
                              ? 'Shopping list queued'
                              : 'Building your shopping list'}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {recipesPreparing
                            ? `${recipePreparation.ready} of ${recipePreparation.required} recipes are ready.`
                            : `${recipePreparation.required > 0 ? 'All recipes are ready.' : 'The plan is ready.'} Chef is combining ingredients and quantities now.`}{' '}
                        This page will update automatically.
                    </p>
                </div>
            )}
        </section>
    );
}

function ReadyShoppingList({
    budget,
    cartAutomation,
    failedMeals,
    included,
    missingMeals,
    onPrepare,
    planId,
    recipePreparation,
    retailers,
    shoppingCategories,
    shoppingList,
}: {
    budget: ShoppingWorkspace['budget'];
    cartAutomation: ShoppingWorkspace['cart_automation'];
    failedMeals: MissingMeal[];
    included: number;
    missingMeals: ShoppingWorkspace['missing_meals'];
    onPrepare: () => void;
    planId: number;
    recipePreparation: ShoppingWorkspace['recipe_preparation'];
    retailers: ShoppingWorkspace['retailers'];
    shoppingCategories: ShoppingWorkspace['shopping_categories'];
    shoppingList: PreparedShoppingList;
}) {
    const active = recipePreparation.preparing > 0;

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

            <CartAutomationSection
                automation={cartAutomation}
                shoppingList={shoppingList}
                retailers={retailers}
            />
            <ShoppingItemsSection
                included={included}
                shoppingCategories={shoppingCategories}
                shoppingList={shoppingList}
            />
            {shoppingList.items.length > 0 && (
                <details className="mt-8 border-t py-4">
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
                    cartSnapshot={
                        cartAutomation.run?.status === 'ready_for_review'
                            ? cartAutomation.run.snapshot
                            : null
                    }
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
        budget,
        cart_automation: cartAutomation,
    } = workspace;
    const remaining =
        shoppingList?.items.filter(
            (item) =>
                item.included &&
                !item.in_pantry &&
                !item.checked &&
                !item.ordered_at,
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
    const completionDisabledReason = shoppingList
        ? shoppingCompletionDisabledReason(
              shoppingList,
              remaining,
              missingMeals,
              completionForm.processing,
          )
        : null;
    const listPreparing =
        shoppingList !== null && shoppingList.generation_status !== 'ready';
    const recipesPreparing = recipePreparation.preparing > 0;
    const preparationActive =
        recipesPreparing || shoppingList?.generation_status === 'processing';
    const automationPolling = Boolean(
        cartAutomation.run &&
        activeAutomationStatuses.has(cartAutomation.run.status),
    );
    const generationQueued =
        shoppingList?.generation_status === 'pending' &&
        shoppingList.generation_failure_code === null;
    const shouldPoll =
        preparationActive || generationQueued || automationPolling;

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
                    <header className="flex flex-col items-start gap-5 border-b pb-6 sm:flex-row sm:justify-between">
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
                                            : shoppingList.generation_status ===
                                                'failed'
                                              ? 'Needs attention'
                                              : shoppingList.generation_status !==
                                                  'ready'
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
                        {shoppingList &&
                            !listPreparing &&
                            shoppingList.status !== 'completed' &&
                            completionDisabledReason === null && (
                                <Button
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

                    {plan.safety_review_required ? (
                        <SafetyReviewRequired planId={plan.id} />
                    ) : !shoppingList ? (
                        <StartShoppingList onPrepare={prepareShopping} />
                    ) : listPreparing ? (
                        <PreparingShoppingList
                            failedMeals={failedMeals}
                            onPrepare={prepareShopping}
                            recipesPreparing={recipesPreparing}
                            recipePreparation={recipePreparation}
                            shoppingList={shoppingList}
                        />
                    ) : (
                        <ReadyShoppingList
                            budget={budget}
                            cartAutomation={cartAutomation}
                            failedMeals={failedMeals}
                            included={included}
                            missingMeals={missingMeals}
                            onPrepare={prepareShopping}
                            planId={plan.id}
                            recipePreparation={recipePreparation}
                            retailers={retailers}
                            shoppingCategories={shoppingCategories}
                            shoppingList={shoppingList}
                        />
                    )}
                    <ShoppingConversation
                        conversation={conversation}
                        planId={plan.id}
                        shoppingList={shoppingList}
                    />
                </div>
            </main>
        </>
    );
}

ShoppingShow.layout = ({ workspace }: { workspace: ShoppingWorkspace }) => ({
    headerBackLink: {
        title: 'Back to plan',
        href: `/meal-plans/${workspace.plan.id}`,
    },
});
