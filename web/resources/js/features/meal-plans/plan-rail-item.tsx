import { Link, router, useForm, usePage } from '@inertiajs/react';
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { destroy, show, update } from '@/routes/meal-plans';
import type { RecentMealPlan } from '@/types/global';

export function PlanRailItem({ plan }: { plan: RecentMealPlan }) {
    const { url } = usePage();
    const [renameOpen, setRenameOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const renameForm = useForm({
        title: plan.title,
        expected_revision: plan.revision,
    });
    const planUrl = show.url(plan.id);
    const currentPath = url.split('?')[0].replace(/\/$/, '');
    const isActive = currentPath === planUrl;

    const handleRenameOpenChange = (open: boolean) => {
        setRenameOpen(open);

        if (open) {
            renameForm.clearErrors();
            renameForm.setData({
                title: plan.title,
                expected_revision: plan.revision,
            });
        }
    };

    return (
        <>
            <ContextMenu>
                <ContextMenuTrigger
                    data-plan-context-menu={plan.id}
                    className={cn(
                        'group/plan flex min-w-0 items-center rounded-md transition-colors hover:bg-sidebar-accent',
                        isActive &&
                            'bg-sidebar-accent text-sidebar-accent-foreground',
                    )}
                >
                    <Link
                        href={planUrl}
                        className={cn(
                            'relative flex min-w-0 flex-1 items-center px-2 py-1.5 text-sm text-sidebar-foreground',
                            isActive && 'pl-3 font-medium',
                        )}
                        aria-current={isActive ? 'page' : undefined}
                    >
                        {isActive && (
                            <span
                                data-current-plan-indicator
                                aria-hidden="true"
                                className="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-sidebar-primary"
                            />
                        )}
                        <span className="truncate">{plan.title}</span>
                        <span className="ml-2 shrink-0 text-[10px] font-normal text-muted-foreground">
                            {plan.phase}
                        </span>
                        {isActive && (
                            <span className="sr-only"> (current plan)</span>
                        )}
                    </Link>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="mr-0.5 flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-60 outline-none hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring md:opacity-0 md:group-hover/plan:opacity-100 md:focus-visible:opacity-100 md:data-[state=open]:opacity-100"
                                aria-label={`Open actions for ${plan.title}`}
                            >
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent side="right" align="start">
                            {plan.can.update && (
                                <DropdownMenuItem
                                    onSelect={() => setRenameOpen(true)}
                                >
                                    <Pencil />
                                    Rename
                                </DropdownMenuItem>
                            )}
                            {plan.can.update && plan.can.delete && (
                                <DropdownMenuSeparator />
                            )}
                            {plan.can.delete && (
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setDeleteOpen(true)}
                                >
                                    <Trash2 />
                                    Delete
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </ContextMenuTrigger>
                <ContextMenuContent>
                    {plan.can.update && (
                        <ContextMenuItem onClick={() => setRenameOpen(true)}>
                            <Pencil />
                            Rename
                        </ContextMenuItem>
                    )}
                    {plan.can.update && plan.can.delete && (
                        <ContextMenuSeparator />
                    )}
                    {plan.can.delete && (
                        <ContextMenuItem
                            variant="destructive"
                            onClick={() => setDeleteOpen(true)}
                        >
                            <Trash2 />
                            Delete
                        </ContextMenuItem>
                    )}
                </ContextMenuContent>
            </ContextMenu>

            <Dialog open={renameOpen} onOpenChange={handleRenameOpenChange}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename meal plan</DialogTitle>
                        <DialogDescription>
                            Give this plan a name that is easy for your
                            household to recognise.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            renameForm.put(update.url(plan.id), {
                                preserveScroll: true,
                                onSuccess: () => setRenameOpen(false),
                            });
                        }}
                    >
                        <div className="space-y-2">
                            <Label htmlFor={`plan-${plan.id}-title`}>
                                Plan name
                            </Label>
                            <Input
                                id={`plan-${plan.id}-title`}
                                value={renameForm.data.title}
                                onChange={(event) =>
                                    renameForm.setData(
                                        'title',
                                        event.target.value,
                                    )
                                }
                                maxLength={120}
                                autoFocus
                                aria-invalid={Boolean(renameForm.errors.title)}
                            />
                            {renameForm.errors.title && (
                                <p className="text-sm text-destructive">
                                    {renameForm.errors.title}
                                </p>
                            )}
                            {renameForm.errors.expected_revision && (
                                <p className="text-sm text-destructive">
                                    {renameForm.errors.expected_revision}
                                </p>
                            )}
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={
                                    renameForm.processing ||
                                    renameForm.data.title.trim() === ''
                                }
                            >
                                {renameForm.processing ? 'Saving…' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete “{plan.title}”?</DialogTitle>
                        <DialogDescription>
                            This permanently deletes the plan, its meals, and
                            its conversation history. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() =>
                                router.delete(destroy.url(plan.id), {
                                    data: {
                                        redirect_to_dashboard: isActive,
                                    },
                                    preserveScroll: !isActive,
                                    onSuccess: () => setDeleteOpen(false),
                                })
                            }
                        >
                            Delete plan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
