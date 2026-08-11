import { Head, router } from '@inertiajs/react';
import { CalendarDays, List, MessagesSquare } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { MessageScrollerProvider } from '@/components/ui/message-scroller';
import { useAppHeader } from '@/contexts/app-header-context';
import { ConversationComposer } from '@/features/meal-plans/conversation-composer';
import { ConversationPanel } from '@/features/meal-plans/conversation-panel';
import { PlanWorkspace } from '@/features/meal-plans/plan-workspace';
import type { MealPlanWorkspace, PlanView } from '@/features/meal-plans/types';
import { useChefConversation } from '@/features/meal-plans/use-chef-conversation';

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
    const initialView: PlanView =
        workspace.phase === 'calendar' || workspace.phase === 'list'
            ? workspace.phase
            : 'conversation';
    const [view, setView] = useState<PlanView>(initialView);

    useEffect(() => {
        if (
            workspace.readiness.recipes_preparing === 0 &&
            workspace.grocery_preparation.run?.polling !== true
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
        workspace.grocery_preparation.run?.polling,
        workspace.readiness.recipes_preparing,
    ]);

    const openConversation = () => {
        setView('conversation');
        router.get(
            `/meal-plans/${workspace.plan.id}`,
            {},
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

    const composer = (
        <div className="mx-auto w-full max-w-3xl shrink-0">
            <ConversationComposer
                addPhotos={conversation.addPhotos}
                error={conversation.error}
                input={conversation.input}
                onSubmit={(event) => {
                    void conversation.sendMessage(event);
                }}
                removePhoto={conversation.removePhoto}
                selectedPhotos={conversation.selectedPhotos}
                sendPhase={conversation.sendPhase}
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
                        />
                    ) : (
                        <div className="flex min-h-0 flex-1 flex-col">
                            <PlanWorkspace
                                workspace={workspace}
                                view={view === 'calendar' ? 'calendar' : 'list'}
                            />
                            {composer}
                        </div>
                    )}
                </div>
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
