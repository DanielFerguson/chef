import { Head } from '@inertiajs/react';
import { CalendarDays, List, MessagesSquare, PanelRight } from 'lucide-react';
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
import { ConversationPanel } from '@/features/meal-plans/conversation-panel';
import { PlanInspector } from '@/features/meal-plans/plan-inspector';
import { PlanWorkspace } from '@/features/meal-plans/plan-workspace';
import type { MealPlanWorkspace } from '@/features/meal-plans/types';

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
    const [view, setView] = useState<'conversation' | 'calendar' | 'list'>(
        'conversation',
    );
    const [sourceMessageId, setSourceMessageId] = useState<number | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const showMessageSource = (messageId: number) => {
        setSourceMessageId(messageId);
        setView('conversation');
        setDetailsOpen(false);
    };
    const clearSourceMessage = () => {
        setSourceMessageId(null);
    };
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
                    onClick={() => setView('conversation')}
                    aria-label="Conversation"
                >
                    <MessagesSquare />
                    <span className="hidden sm:inline">Conversation</span>
                </Button>
                <Button
                    size="sm"
                    variant={view === 'calendar' ? 'secondary' : 'ghost'}
                    className="px-2 sm:px-3"
                    onClick={() => setView('calendar')}
                    aria-label="Calendar"
                >
                    <CalendarDays />
                    <span className="hidden sm:inline">Calendar</span>
                </Button>
                <Button
                    size="sm"
                    variant={view === 'list' ? 'secondary' : 'ghost'}
                    className="px-2 sm:px-3"
                    onClick={() => setView('list')}
                    aria-label="List"
                >
                    <List />
                    <span className="hidden sm:inline">List</span>
                </Button>
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
                            sourceMessageId={sourceMessageId}
                            onSourceMessageShown={clearSourceMessage}
                            onOpenPlanDetails={() => setDetailsOpen(true)}
                        />
                    ) : (
                        <PlanWorkspace workspace={workspace} view={view} />
                    )}
                </div>
                <PlanInspector
                    workspace={workspace}
                    conversationId={workspace.conversation.id}
                    onShowMessageSource={showMessageSource}
                    className="hidden lg:block"
                />
                <Sheet open={detailsOpen} onOpenChange={setDetailsOpen}>
                    <SheetContent className="w-[min(92vw,24rem)] gap-0 overflow-y-auto p-0">
                        <SheetHeader className="border-b pr-12">
                            <SheetTitle>Plan details</SheetTitle>
                            <SheetDescription>
                                Current plan status, household truth, and
                                sharing.
                            </SheetDescription>
                        </SheetHeader>
                        <PlanInspector
                            workspace={workspace}
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
