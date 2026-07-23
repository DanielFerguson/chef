import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    List,
    MessagesSquare,
    PanelRight,
    ShoppingBasket,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { MessageScrollerProvider } from '@/components/ui/message-scroller';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAppHeader } from '@/contexts/app-header-context';
import { ConversationComposer } from '@/features/meal-plans/conversation-composer';
import { ConversationPanel } from '@/features/meal-plans/conversation-panel';
import { PlanInspector } from '@/features/meal-plans/plan-inspector';
import { PlanWorkspace } from '@/features/meal-plans/plan-workspace';
import type { MealPlanWorkspace, PlanView } from '@/features/meal-plans/types';
import { useChefConversation } from '@/features/meal-plans/use-chef-conversation';
import { ShoppingPhase } from '@/features/shopping/shopping-phase';

function ConversationScroller({ children }: { children: React.ReactNode }) {
    const [autoScroll, setAutoScroll] = useState(false);

    useEffect(() => {
        const frame = window.requestAnimationFrame(() => setAutoScroll(true));

        return () => window.cancelAnimationFrame(frame);
    }, []);

    return (
        <MessageScrollerProvider
            autoScroll={autoScroll}
            defaultScrollPosition="last-anchor"
            scrollPreviousItemPeek={56}
        >
            {children}
        </MessageScrollerProvider>
    );
}

function MealPlanExperience({ workspace }: { workspace: MealPlanWorkspace }) {
    const conversation = useChefConversation(workspace.conversation);
    const shoppingAvailable = workspace.plan.shopping_list != null;
    const initialView: PlanView =
        workspace.phase === 'shopping' && shoppingAvailable
            ? 'shopping'
            : 'conversation';
    const [view, setView] = useState<PlanView>(initialView);
    const [sourceMessageId, setSourceMessageId] = useState<number | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);

    useEffect(() => {
        if (workspace.phase === 'shopping' && shoppingAvailable) {
            setView('shopping');

            return;
        }

        if (!shoppingAvailable) {
            setView((current) =>
                current === 'shopping' ? 'conversation' : current,
            );
        }
    }, [workspace.phase, shoppingAvailable, workspace.plan.id]);

    useEffect(() => {
        const shoppingList = workspace.plan.shopping_list;
        const shoppingPreparing =
            shoppingList !== null &&
            shoppingList.generation_status !== 'ready' &&
            shoppingList.generation_status !== 'failed';
        const orderRunActive = Boolean(
            workspace.shopping?.retailer_order_run &&
                [
                    'preparing_cart',
                    'fetching_fulfilment_options',
                    'submitting_order',
                ].includes(workspace.shopping.retailer_order_run.status),
        );

        if (
            workspace.readiness.recipes_preparing === 0 &&
            !shoppingPreparing &&
            !orderRunActive
        ) {
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
    }, [
        workspace.plan.shopping_list,
        workspace.readiness.recipes_preparing,
        workspace.shopping?.retailer_order_run?.status,
    ]);

    const openShopping = () => {
        if (!shoppingAvailable) {
            return;
        }

        setView('shopping');
        router.get(
            `/meal-plans/${workspace.plan.id}`,
            { phase: 'shopping' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const openConversation = () => {
        setView('conversation');
        router.get(
            `/meal-plans/${workspace.plan.id}`,
            { phase: 'conversation' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const openStructuredView = (next: 'calendar' | 'list') => {
        setView(next);
        router.get(
            `/meal-plans/${workspace.plan.id}`,
            { phase: next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const showMessageSource = (messageId: number) => {
        setSourceMessageId(messageId);
        openConversation();
        setDetailsOpen(false);
    };
    const clearSourceMessage = () => {
        setSourceMessageId(null);
    };

    const composer = (
        <div className="mx-auto w-full max-w-3xl shrink-0">
            <ConversationComposer
                error={conversation.error}
                input={conversation.input}
                onSubmit={(event) => {
                    void conversation.sendMessage(event);
                }}
                sending={conversation.sending}
                setInput={conversation.setInput}
            />
        </div>
    );

    const headerContent = (
        <div className="flex min-w-0 flex-1 items-center gap-2">
            <h1 className="max-w-28 shrink truncate text-sm font-medium sm:max-w-48 lg:max-w-none">
                {workspace.plan.title}
            </h1>
            <nav
                aria-label="Meal plan view"
                className="ml-auto flex shrink-0 items-center gap-0.5"
            >
                <Button
                    size="sm"
                    variant={view === 'conversation' ? 'secondary' : 'ghost'}
                    className="px-2 sm:px-3"
                    onClick={openConversation}
                    aria-label="Conversation"
                >
                    <MessagesSquare />
                    <span className="hidden sm:inline">Conversation</span>
                </Button>
                <Button
                    size="sm"
                    variant={view === 'calendar' ? 'secondary' : 'ghost'}
                    className="px-2 sm:px-3"
                    onClick={() => openStructuredView('calendar')}
                    aria-label="Calendar"
                >
                    <CalendarDays />
                    <span className="hidden sm:inline">Calendar</span>
                </Button>
                <Button
                    size="sm"
                    variant={view === 'list' ? 'secondary' : 'ghost'}
                    className="px-2 sm:px-3"
                    onClick={() => openStructuredView('list')}
                    aria-label="List"
                >
                    <List />
                    <span className="hidden sm:inline">List</span>
                </Button>
                {shoppingAvailable && (
                    <Button
                        size="sm"
                        variant={view === 'shopping' ? 'secondary' : 'ghost'}
                        className="px-2 sm:px-3"
                        onClick={openShopping}
                        aria-label="Shopping"
                    >
                        <ShoppingBasket />
                        <span className="hidden sm:inline">Shopping</span>
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="ghost"
                    className="px-2 lg:hidden"
                    onClick={() => setDetailsOpen(true)}
                    aria-label="Open plan details"
                >
                    <PanelRight />
                    <span className="hidden md:inline">Details</span>
                </Button>
            </nav>
        </div>
    );
    const headerPortal = useAppHeader(headerContent);

    return (
        <>
            {headerPortal}
            <div
                key={workspace.plan.id}
                className="flex min-h-0 flex-1 flex-col bg-background lg:h-[calc(100svh-5rem)] lg:flex-none lg:flex-row lg:overflow-hidden"
            >
                <div className="flex min-h-0 min-w-0 flex-1 flex-col lg:overflow-hidden">
                    {view === 'conversation' ? (
                        <ConversationPanel
                            key={workspace.conversation.id}
                            workspace={workspace}
                            conversation={conversation}
                            sourceMessageId={sourceMessageId}
                            onSourceMessageShown={clearSourceMessage}
                            onOpenPlanDetails={() => setDetailsOpen(true)}
                            onOpenShopping={openShopping}
                        />
                    ) : view === 'shopping' && workspace.shopping ? (
                        <div className="flex min-h-0 flex-1 flex-col">
                            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-6 sm:px-8">
                                <div className="mx-auto w-full max-w-4xl">
                                    <ShoppingPhase
                                        workspace={workspace.shopping}
                                    />
                                </div>
                            </div>
                            {composer}
                        </div>
                    ) : (
                        <div className="flex min-h-0 flex-1 flex-col">
                            <PlanWorkspace
                                workspace={workspace}
                                view={
                                    view === 'calendar' ? 'calendar' : 'list'
                                }
                            />
                            {composer}
                        </div>
                    )}
                </div>
                <PlanInspector
                    workspace={workspace}
                    view={view}
                    conversationId={workspace.conversation.id}
                    onShowMessageSource={showMessageSource}
                    className="hidden lg:block"
                />
                <Sheet open={detailsOpen} onOpenChange={setDetailsOpen}>
                    <SheetContent className="w-[min(92vw,24rem)] gap-0 overflow-y-auto p-0">
                        <SheetHeader className="border-b pr-12">
                            <SheetTitle>
                                {view === 'shopping'
                                    ? 'Shopping details'
                                    : 'Plan details'}
                            </SheetTitle>
                            <SheetDescription>
                                {view === 'shopping'
                                    ? 'Budget, source meals, and automation status for this shop.'
                                    : 'Current plan status, household truth, and sharing.'}
                            </SheetDescription>
                        </SheetHeader>
                        <PlanInspector
                            workspace={workspace}
                            view={view}
                            conversationId={workspace.conversation.id}
                            onShowMessageSource={showMessageSource}
                            className="border-0"
                        />
                    </SheetContent>
                </Sheet>
            </div>
        </>
    );
}

export default function MealPlanShow({
    workspace,
}: {
    workspace: MealPlanWorkspace;
}) {
    return (
        <>
            <Head title={workspace.plan.title} />
            <ConversationScroller key={workspace.conversation.id}>
                <MealPlanExperience workspace={workspace} />
            </ConversationScroller>
        </>
    );
}
