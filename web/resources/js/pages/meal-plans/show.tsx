import { Head } from '@inertiajs/react';
import { CalendarDays, List, MessagesSquare } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConversationPanel } from '@/features/meal-plans/conversation-panel';
import { PlanInspector } from '@/features/meal-plans/plan-inspector';
import { PlanWorkspace } from '@/features/meal-plans/plan-workspace';
import type { MealPlanWorkspace } from '@/features/meal-plans/types';

export default function MealPlanShow({
    workspace,
}: {
    workspace: MealPlanWorkspace;
}) {
    const [view, setView] = useState<'conversation' | 'calendar' | 'list'>(
        'conversation',
    );

    return (
        <>
            <Head title={workspace.plan.title} />
            <div
                key={workspace.plan.id}
                className="flex min-h-0 flex-1 flex-col bg-background lg:h-[calc(100svh-5rem)] lg:flex-none lg:flex-row lg:overflow-hidden"
            >
                <div className="flex min-h-0 min-w-0 flex-1 flex-col lg:overflow-hidden">
                    <nav
                        aria-label="Meal plan view"
                        className="flex shrink-0 items-center gap-1 border-b px-4 py-2 sm:px-7"
                    >
                        <Button
                            size="sm"
                            variant={
                                view === 'conversation' ? 'secondary' : 'ghost'
                            }
                            onClick={() => setView('conversation')}
                        >
                            <MessagesSquare /> Conversation
                        </Button>
                        <Button
                            size="sm"
                            variant={
                                view === 'calendar' ? 'secondary' : 'ghost'
                            }
                            onClick={() => setView('calendar')}
                        >
                            <CalendarDays /> Calendar
                        </Button>
                        <Button
                            size="sm"
                            variant={view === 'list' ? 'secondary' : 'ghost'}
                            onClick={() => setView('list')}
                        >
                            <List /> List
                        </Button>
                    </nav>
                    {view === 'conversation' ? (
                        <ConversationPanel
                            key={workspace.conversation.id}
                            workspace={workspace}
                        />
                    ) : (
                        <PlanWorkspace workspace={workspace} view={view} />
                    )}
                </div>
                <PlanInspector workspace={workspace} />
            </div>
        </>
    );
}
