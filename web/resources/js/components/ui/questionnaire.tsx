import { Questionnaire as QuestionnairePrimitive } from '@shadcn/react/questionnaire';
import { Check } from 'lucide-react';
import * as React from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

function Questionnaire({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Root>) {
    return (
        <QuestionnairePrimitive.Root
            data-slot="questionnaire"
            className={cn(
                'flex w-full min-w-0 flex-col gap-4',
                className,
            )}
            {...props}
        />
    );
}

function QuestionnaireProgress({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Progress>) {
    return (
        <QuestionnairePrimitive.Progress
            data-slot="questionnaire-progress"
            className={cn(
                'min-h-[1lh] w-fit min-w-[14ch] text-xs font-medium text-muted-foreground tabular-nums',
                className,
            )}
            {...props}
        />
    );
}

function QuestionnaireItem({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Item>) {
    return (
        <QuestionnairePrimitive.Item
            data-slot="questionnaire-item"
            className={cn(
                'min-w-0 space-y-4 border-0 p-0 outline-none',
                className,
            )}
            {...props}
        />
    );
}

function QuestionnaireTitle({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Title>) {
    return (
        <QuestionnairePrimitive.Title
            data-slot="questionnaire-title"
            className={cn(
                'text-base leading-6 font-semibold text-pretty',
                className,
            )}
            {...props}
        />
    );
}

function QuestionnaireDescription({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Description>) {
    return (
        <QuestionnairePrimitive.Description
            data-slot="questionnaire-description"
            className={cn(
                'text-sm leading-6 text-pretty text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

function QuestionnaireChoices({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Choices>) {
    return (
        <QuestionnairePrimitive.Choices
            data-slot="questionnaire-choices"
            className={cn('grid min-w-0 gap-2', className)}
            {...props}
        />
    );
}

function QuestionnaireChoice({
    children,
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Choice>) {
    return (
        <QuestionnairePrimitive.Choice
            data-slot="questionnaire-choice"
            className={cn(
                'group/questionnaire-choice relative flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-input bg-background px-3 py-3 text-start shadow-xs transition-[border-color,background-color,box-shadow] outline-none select-none',
                'hover:bg-accent/60 focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50',
                'data-checked:border-primary data-checked:bg-primary/5 data-disabled:pointer-events-none data-disabled:cursor-not-allowed data-disabled:opacity-50',
                'has-[[aria-invalid=true]]:border-destructive has-[[aria-invalid=true]]:ring-3 has-[[aria-invalid=true]]:ring-destructive/20 dark:bg-input/20 dark:data-checked:bg-primary/10 dark:has-[[aria-invalid=true]]:ring-destructive/40',
                className,
            )}
            {...props}
        >
            <QuestionnairePrimitive.ChoiceInput
                data-slot="questionnaire-choice-input"
                className="absolute inset-0 z-10 size-full cursor-pointer opacity-0"
            />
            <span
                aria-hidden="true"
                data-slot="questionnaire-choice-indicator"
                className="pointer-events-none relative mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-[5px] border border-input bg-background text-primary-foreground group-data-[type=radio]/questionnaire-choice:rounded-full group-data-checked/questionnaire-choice:border-primary group-data-checked/questionnaire-choice:bg-primary"
            >
                <span
                    data-slot="questionnaire-choice-indicator-dot"
                    className="hidden size-2 rounded-full bg-current group-data-[type=checkbox]/questionnaire-choice:hidden group-data-checked/questionnaire-choice:block"
                />
                <Check
                    data-slot="questionnaire-choice-indicator-check"
                    className="hidden size-3.5 group-data-[type=radio]/questionnaire-choice:hidden group-data-checked/questionnaire-choice:block"
                />
            </span>
            <QuestionnairePrimitive.ChoiceLabel
                data-slot="questionnaire-choice-label"
                className="flex min-w-0 flex-1 flex-col text-sm leading-5"
            >
                {children}
            </QuestionnairePrimitive.ChoiceLabel>
            <QuestionnairePrimitive.ChoiceShortcut
                data-slot="questionnaire-choice-shortcut"
                className="pointer-events-none ms-auto hidden shrink-0 rounded border bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground group-data-[shortcut]/questionnaire-choice:inline-flex"
            />
        </QuestionnairePrimitive.Choice>
    );
}

function QuestionnaireChoiceDescription({
    className,
    ...props
}: React.ComponentProps<'span'>) {
    return (
        <span
            data-slot="questionnaire-choice-description"
            className={cn('mt-0.5 text-xs text-muted-foreground', className)}
            {...props}
        />
    );
}

function QuestionnaireInput({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Input>) {
    return (
        <QuestionnairePrimitive.Input
            data-slot="questionnaire-input"
            className={cn(
                'min-h-11 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow,background-color] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-9 sm:text-sm',
                'focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:bg-input/30 dark:aria-invalid:ring-destructive/40',
                className,
            )}
            {...props}
        />
    );
}

function QuestionnaireError({
    className,
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Error>) {
    return (
        <QuestionnairePrimitive.Error
            data-slot="questionnaire-error"
            className={cn('text-sm text-destructive', className)}
            {...props}
        />
    );
}

function QuestionnaireActions({
    className,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="questionnaire-actions"
            className={cn(
                'grid min-h-11 w-full grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2',
                className,
            )}
            {...props}
        />
    );
}

type QuestionnaireButtonProps = Pick<
    React.ComponentProps<typeof Button>,
    'size' | 'variant'
>;

function QuestionnairePrevious({
    children,
    className,
    size = 'default',
    variant = 'outline',
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Previous> &
    QuestionnaireButtonProps) {
    return (
        <QuestionnairePrimitive.Previous
            data-slot="questionnaire-previous"
            data-size={size}
            data-variant={variant}
            className={cn(
                buttonVariants({ size, variant }),
                'col-start-1 row-start-1 min-h-11 justify-self-start sm:min-h-0',
                className,
            )}
            {...props}
        >
            {children ?? 'Previous'}
        </QuestionnairePrimitive.Previous>
    );
}

function QuestionnaireSkip({
    children,
    className,
    size = 'default',
    variant = 'outline',
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Skip> &
    QuestionnaireButtonProps) {
    return (
        <QuestionnairePrimitive.Skip
            data-slot="questionnaire-skip"
            data-size={size}
            data-variant={variant}
            className={cn(
                buttonVariants({ size, variant }),
                'col-start-2 row-start-1 min-h-11 justify-self-end sm:min-h-0',
                className,
            )}
            {...props}
        >
            {children ?? 'Skip'}
        </QuestionnairePrimitive.Skip>
    );
}

function QuestionnaireNext({
    children,
    className,
    size = 'default',
    variant = 'default',
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Next> &
    QuestionnaireButtonProps) {
    return (
        <QuestionnairePrimitive.Next
            data-slot="questionnaire-next"
            data-size={size}
            data-variant={variant}
            className={cn(
                buttonVariants({ size, variant }),
                'col-start-3 row-start-1 min-h-11 justify-self-end sm:min-h-0',
                className,
            )}
            {...props}
        >
            {children ?? 'Next'}
        </QuestionnairePrimitive.Next>
    );
}

function QuestionnaireSubmit({
    children,
    className,
    size = 'default',
    variant = 'default',
    ...props
}: React.ComponentProps<typeof QuestionnairePrimitive.Submit> &
    QuestionnaireButtonProps) {
    return (
        <QuestionnairePrimitive.Submit
            data-slot="questionnaire-submit"
            data-size={size}
            data-variant={variant}
            className={cn(
                buttonVariants({ size, variant }),
                'col-start-3 row-start-1 min-h-11 justify-self-end sm:min-h-0',
                className,
            )}
            {...props}
        >
            {children ?? 'Submit'}
        </QuestionnairePrimitive.Submit>
    );
}

export {
    Questionnaire,
    QuestionnaireActions,
    QuestionnaireChoice,
    QuestionnaireChoiceDescription,
    QuestionnaireChoices,
    QuestionnaireDescription,
    QuestionnaireError,
    QuestionnaireInput,
    QuestionnaireItem,
    QuestionnaireNext,
    QuestionnairePrevious,
    QuestionnaireProgress,
    QuestionnaireSkip,
    QuestionnaireSubmit,
    QuestionnaireTitle,
};
