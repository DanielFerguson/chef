import { Head } from '@inertiajs/react';
import { ConversationPanel } from '@/features/meal-plans/conversation-panel';
import { PlanInspector } from '@/features/meal-plans/plan-inspector';
import type { MealPlanWorkspace } from '@/features/meal-plans/types';

export default function MealPlanShow({
    workspace,
}: {
    workspace: MealPlanWorkspace;
}) {
    return (
        <>
            <Head title={workspace.plan.title} />
            <div
                key={workspace.plan.id}
                className="flex min-h-0 flex-1 flex-col bg-background lg:flex-row"
            >
                <ConversationPanel
                    key={workspace.conversation.id}
                    workspace={workspace}
                />
                <PlanInspector workspace={workspace} />
            </div>
        </>
    );
}
