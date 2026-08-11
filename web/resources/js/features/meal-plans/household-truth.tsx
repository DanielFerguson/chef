import { router, useForm } from '@inertiajs/react';
import {
    Check,
    LoaderCircle,
    MessagesSquare,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    UsersRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import {
    destroy as destroyConstraint,
    store as storeConstraint,
    update as updateConstraint,
} from '@/actions/App/Http/Controllers/ConstraintController';
import storeSafetyReview from '@/actions/App/Http/Controllers/MealPlanSafetyReviewController';
import {
    destroy as destroyPreference,
    update as updatePreference,
} from '@/actions/App/Http/Controllers/PreferenceController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
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
} from '@/components/ui/questionnaire';
import { cn } from '@/lib/utils';
import type { Constraint, MealPlanWorkspace, Preference } from './types';

function groupByOwner<T extends { person_id: number | null }>(items: T[]) {
    const groups = new Map<number | null, T[]>();

    items.forEach((item) => {
        const ownerItems = groups.get(item.person_id);

        if (ownerItems) {
            ownerItems.push(item);
        } else {
            groups.set(item.person_id, [item]);
        }
    });

    return groups;
}

function TruthGroup({
    title,
    empty,
    children,
}: {
    title: string;
    empty: string;
    children: React.ReactNode[];
}) {
    return (
        <div>
            <h3 className="text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            {children.length > 0 ? (
                <ul>{children}</ul>
            ) : (
                <p className="py-1.5 text-xs text-muted-foreground">{empty}</p>
            )}
        </div>
    );
}

function TruthOwnerGroup({
    owner,
    category,
    children,
}: {
    owner: string;
    category: string;
    children: React.ReactNode[];
}) {
    return (
        <li className="py-1 first:pt-1 last:pb-0" data-truth-owner={owner}>
            <h4 className="text-xs font-medium text-foreground">{owner}</h4>
            <ul className="mt-0.5" aria-label={`${owner} ${category}`}>
                {children}
            </ul>
        </li>
    );
}

function TruthActions({ children }: { children: React.ReactNode }) {
    return (
        <div
            data-truth-actions
            className="ml-auto flex shrink-0 items-center rounded-md bg-background/95 pl-1"
        >
            {children}
        </div>
    );
}

function EditablePreference({
    preference,
    conversationId,
    onShowMessageSource,
}: {
    preference: Preference;
    conversationId: number;
    onShowMessageSource: (messageId: number) => void;
}) {
    const [draft, setDraft] = useState<string | null>(null);

    const save = () => {
        if (draft === null) {
            return;
        }

        router.put(
            updatePreference.url(preference.id),
            {
                subject: draft,
                sentiment: preference.sentiment,
                strength: preference.strength,
            },
            {
                preserveScroll: true,
                onSuccess: () => setDraft(null),
            },
        );
    };

    return (
        <li className="group relative flex min-h-7 items-center gap-2 py-0.5 text-sm">
            <span aria-hidden="true" className="text-muted-foreground">
                •
            </span>
            {draft !== null ? (
                <>
                    <Input
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        className="h-7"
                        aria-label="Preference"
                    />
                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-7"
                        onClick={save}
                    >
                        <Check />
                        <span className="sr-only">Save preference</span>
                    </Button>
                </>
            ) : (
                <>
                    {preference.source_message?.conversation_id ===
                        conversationId && (
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7 shrink-0"
                            onClick={() =>
                                onShowMessageSource(
                                    preference.source_message!.id,
                                )
                            }
                            title="View source message"
                            aria-label={`View source for ${preference.subject}`}
                        >
                            <MessagesSquare />
                            <span className="sr-only">
                                View source for {preference.subject}
                            </span>
                        </Button>
                    )}
                    <span className="min-w-0 flex-1 truncate">
                        {preference.sentiment === 'dislike'
                            ? 'Avoid '
                            : 'Likes '}
                        {preference.subject}
                    </span>
                    {preference.provenance === 'feedback' && (
                        <Badge variant="outline" className="font-normal">
                            feedback
                        </Badge>
                    )}
                    <TruthActions>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            onClick={() => setDraft(preference.subject)}
                        >
                            <Pencil />
                            <span className="sr-only">Edit preference</span>
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            onClick={() =>
                                router.delete(
                                    destroyPreference.url(preference.id),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Trash2 />
                            <span className="sr-only">Delete preference</span>
                        </Button>
                    </TruthActions>
                </>
            )}
        </li>
    );
}

function EditableConstraint({
    constraint,
    conversationId,
    onShowMessageSource,
}: {
    constraint: Constraint;
    conversationId: number;
    onShowMessageSource: (messageId: number) => void;
}) {
    const [draft, setDraft] = useState<string | null>(null);
    const source = constraint.confirmation_message;

    const save = () => {
        if (draft === null) {
            return;
        }

        router.put(
            updateConstraint.url(constraint.id),
            {
                subject: draft,
                details: constraint.details,
                severity: constraint.severity,
                explicitly_confirmed: true,
            },
            {
                preserveScroll: true,
                onSuccess: () => setDraft(null),
            },
        );
    };

    return (
        <li className="py-1 text-sm">
            <div className="group relative flex min-h-7 items-center gap-2">
                <ShieldCheck className="size-4 shrink-0 text-primary" />
                {draft !== null ? (
                    <>
                        <Input
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            className="h-7"
                            aria-label="Safety rule"
                        />
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            onClick={save}
                        >
                            <Check />
                            <span className="sr-only">Save constraint</span>
                        </Button>
                    </>
                ) : (
                    <>
                        <span className="min-w-0 flex-1 truncate">
                            {constraint.subject}
                        </span>
                        <Badge variant="outline" className="font-normal">
                            {constraint.kind}
                        </Badge>
                        <TruthActions>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                onClick={() => setDraft(constraint.subject)}
                            >
                                <Pencil />
                                <span className="sr-only">Edit constraint</span>
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                onClick={() =>
                                    router.delete(
                                        destroyConstraint.url(constraint.id),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 />
                                <span className="sr-only">
                                    Delete constraint
                                </span>
                            </Button>
                        </TruthActions>
                    </>
                )}
            </div>
            {draft === null &&
                source &&
                (source.conversation_id === conversationId ? (
                    <button
                        type="button"
                        className="mt-1 ml-6 line-clamp-2 text-left text-xs leading-5 text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        onClick={() => onShowMessageSource(source.id)}
                        aria-label={`View source for ${constraint.subject}`}
                    >
                        Confirmed by{' '}
                        {source.author?.name ?? 'a household member'}: “
                        {source.content}”
                    </button>
                ) : (
                    <p className="mt-1 ml-6 line-clamp-2 text-xs leading-5 text-muted-foreground">
                        Confirmed by{' '}
                        {source.author?.name ?? 'a household member'}: “
                        {source.content}”
                    </p>
                ))}
        </li>
    );
}

const CONSTRAINT_KINDS = [
    {
        value: 'allergy',
        label: 'Allergy',
        description:
            'An ingredient or substance that causes an allergic reaction.',
    },
    {
        value: 'medical',
        label: 'Medical',
        description: 'A medically required food or preparation restriction.',
    },
    {
        value: 'dietary',
        label: 'Dietary',
        description: 'A firm dietary requirement rather than a preference.',
    },
    {
        value: 'religious',
        label: 'Religious',
        description:
            'A food or preparation rule connected to religious practice.',
    },
    {
        value: 'accessibility',
        label: 'Accessibility',
        description:
            'A requirement that makes preparation or eating accessible.',
    },
    {
        value: 'other',
        label: 'Other safety rule',
        description: 'Another explicit household safety requirement.',
    },
] as const;

type SafetyItem =
    'applies_to' | 'kind' | 'subject' | 'details' | 'confirmation';

type ConstraintFormData = {
    person_id: number | 'household' | '';
    kind: string;
    subject: string;
    details: string;
    severity: string;
    explicitly_confirmed: boolean;
};

const safetyItemForError: Record<string, SafetyItem> = {
    person_id: 'applies_to',
    kind: 'kind',
    subject: 'subject',
    details: 'details',
    severity: 'details',
    explicitly_confirmed: 'confirmation',
};

export function SafetyRules({
    household,
    planId,
    safetyReviewRequired,
    conversationId,
    onShowMessageSource,
    embedded = false,
}: {
    household: MealPlanWorkspace['household'];
    planId: number;
    safetyReviewRequired: boolean;
    conversationId: number;
    onShowMessageSource: (messageId: number) => void;
    embedded?: boolean;
}) {
    const ownerNames = new Map(
        household.people.map((person) => [person.id, person.name]),
    );
    const constraints = [
        ...household.constraints,
        ...household.people.flatMap((person) => person.constraints),
    ];
    const [addingConstraint, setAddingConstraint] = useState(false);
    const [currentItem, setCurrentItem] = useState<SafetyItem>('applies_to');
    const constraintForm = useForm<ConstraintFormData>({
        person_id: '',
        kind: '',
        subject: '',
        details: '',
        severity: '',
        explicitly_confirmed: false,
    });
    const safetyReviewForm = useForm({ explicitly_reviewed: true });
    const questionnaireItems = useMemo(
        () => [
            {
                name: 'applies_to',
                required: true,
                choices: [
                    { value: 'household' },
                    ...household.people.map((person) => ({
                        value: String(person.id),
                    })),
                ],
            },
            {
                name: 'kind',
                required: true,
                choices: CONSTRAINT_KINDS.map((kind) => ({
                    value: kind.value,
                })),
            },
            { name: 'subject', required: true },
            { name: 'details' },
            {
                name: 'confirmation',
                required: true,
                choices: [{ value: 'confirmed' }],
            },
        ],
        [household.people],
    );
    const ownerFor = (personId: number | null) =>
        personId === null
            ? household.name
            : (ownerNames.get(personId) ?? 'Household member');
    const selectedOwner =
        constraintForm.data.person_id === 'household'
            ? household.name
            : constraintForm.data.person_id === ''
              ? 'Not selected'
              : (ownerNames.get(constraintForm.data.person_id) ??
                'Household member');
    const selectedKind =
        CONSTRAINT_KINDS.find((kind) => kind.value === constraintForm.data.kind)
            ?.label ?? 'Not selected';
    const constraintGroups = Array.from(
        groupByOwner(constraints),
        ([personId, ownerConstraints]) => (
            <TruthOwnerGroup
                key={personId ?? 'household'}
                owner={ownerFor(personId)}
                category="safety rules"
            >
                {ownerConstraints.map((constraint) => (
                    <EditableConstraint
                        key={constraint.id}
                        constraint={constraint}
                        conversationId={conversationId}
                        onShowMessageSource={onShowMessageSource}
                    />
                ))}
            </TruthOwnerGroup>
        ),
    );

    const cancelConstraint = () => {
        constraintForm.reset();
        constraintForm.clearErrors();
        setCurrentItem('applies_to');
        setAddingConstraint(false);
    };

    const submitConstraint = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        constraintForm.clearErrors();
        constraintForm.transform((data) => ({
            ...data,
            person_id: data.person_id === 'household' ? null : data.person_id,
            details: data.details.trim() || null,
            severity: null,
        }));
        constraintForm.post(storeConstraint.url(), {
            preserveScroll: true,
            onSuccess: cancelConstraint,
            onError: (errors) => {
                const firstError = Object.keys(errors).find(
                    (field) => safetyItemForError[field],
                );

                if (firstError) {
                    setCurrentItem(safetyItemForError[firstError]);
                }
            },
        });
    };

    return (
        <section
            data-household-safety
            className={cn(
                !embedded &&
                    'mx-auto w-full max-w-xl rounded-xl border bg-card p-4 shadow-sm sm:p-5',
            )}
        >
            {!embedded && (
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 rounded-md bg-primary/10 p-2 text-primary">
                        <ShieldCheck className="size-4" />
                    </span>
                    <div>
                        <h2 className="text-sm font-medium">
                            Household safety
                        </h2>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            Structured, explicit safety records. Chef never
                            infers allergies from preferences or meal feedback.
                        </p>
                    </div>
                </div>
            )}

            <div className={cn(!embedded && 'mt-4')}>
                <TruthGroup
                    title="Safety rules"
                    empty={
                        safetyReviewRequired
                            ? 'Safety details have not been reviewed yet'
                            : 'No allergies or safety rules reported for this plan'
                    }
                >
                    {constraintGroups}
                </TruthGroup>
            </div>

            {addingConstraint ? (
                <Questionnaire
                    className="mt-4 rounded-xl border bg-muted/20 p-4"
                    items={questionnaireItems}
                    item={currentItem}
                    onItemChange={(item) => setCurrentItem(item as SafetyItem)}
                    onSubmit={submitConstraint}
                >
                    <div className="flex items-center justify-between gap-3">
                        <QuestionnaireProgress />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={constraintForm.processing}
                            onClick={cancelConstraint}
                        >
                            Cancel
                        </Button>
                    </div>

                    <QuestionnaireItem
                        name="applies_to"
                        required
                        invalid={Boolean(constraintForm.errors.person_id)}
                    >
                        <QuestionnaireTitle>
                            Who does this safety rule apply to?
                        </QuestionnaireTitle>
                        <QuestionnaireDescription>
                            Choose the whole household or one person. Do not
                            guess when the scope is unclear.
                        </QuestionnaireDescription>
                        <QuestionnaireChoices>
                            <QuestionnaireChoice
                                value="household"
                                checked={
                                    constraintForm.data.person_id ===
                                    'household'
                                }
                                onChange={(event) => {
                                    if (event.target.checked) {
                                        constraintForm.setData(
                                            'person_id',
                                            'household',
                                        );
                                    }
                                }}
                            >
                                Whole household
                            </QuestionnaireChoice>
                            {household.people.map((person) => (
                                <QuestionnaireChoice
                                    key={person.id}
                                    value={String(person.id)}
                                    checked={
                                        constraintForm.data.person_id ===
                                        person.id
                                    }
                                    onChange={(event) => {
                                        if (event.target.checked) {
                                            constraintForm.setData(
                                                'person_id',
                                                person.id,
                                            );
                                        }
                                    }}
                                >
                                    {person.name}
                                </QuestionnaireChoice>
                            ))}
                        </QuestionnaireChoices>
                        <QuestionnaireError>
                            {constraintForm.errors.person_id}
                        </QuestionnaireError>
                    </QuestionnaireItem>

                    <QuestionnaireItem
                        name="kind"
                        required
                        invalid={Boolean(constraintForm.errors.kind)}
                    >
                        <QuestionnaireTitle>
                            What kind of rule is it?
                        </QuestionnaireTitle>
                        <QuestionnaireDescription>
                            Pick the meaning the household explicitly stated.
                        </QuestionnaireDescription>
                        <QuestionnaireChoices>
                            {CONSTRAINT_KINDS.map((kind) => (
                                <QuestionnaireChoice
                                    key={kind.value}
                                    value={kind.value}
                                    checked={
                                        constraintForm.data.kind === kind.value
                                    }
                                    onChange={(event) => {
                                        if (event.target.checked) {
                                            constraintForm.setData(
                                                'kind',
                                                kind.value,
                                            );
                                        }
                                    }}
                                >
                                    {kind.label}
                                    <QuestionnaireChoiceDescription>
                                        {kind.description}
                                    </QuestionnaireChoiceDescription>
                                </QuestionnaireChoice>
                            ))}
                        </QuestionnaireChoices>
                        <QuestionnaireError>
                            {constraintForm.errors.kind}
                        </QuestionnaireError>
                    </QuestionnaireItem>

                    <QuestionnaireItem
                        name="subject"
                        required
                        invalid={Boolean(constraintForm.errors.subject)}
                    >
                        <QuestionnaireTitle>
                            State the safety rule
                        </QuestionnaireTitle>
                        <QuestionnaireDescription>
                            Use the household&apos;s own clear wording, such as
                            “Peanut allergy” or “No cooking alcohol”.
                        </QuestionnaireDescription>
                        <QuestionnaireInput
                            aria-label="Safety rule subject"
                            placeholder="Safety rule"
                            maxLength={255}
                            value={constraintForm.data.subject}
                            onChange={(event) =>
                                constraintForm.setData(
                                    'subject',
                                    event.target.value,
                                )
                            }
                        />
                        <QuestionnaireError>
                            {constraintForm.errors.subject}
                        </QuestionnaireError>
                    </QuestionnaireItem>

                    <QuestionnaireItem
                        name="details"
                        invalid={Boolean(constraintForm.errors.details)}
                    >
                        <QuestionnaireTitle>
                            Add any important details
                        </QuestionnaireTitle>
                        <QuestionnaireDescription>
                            Optional preparation or cross-contamination details.
                        </QuestionnaireDescription>
                        <QuestionnaireInput
                            aria-label="Safety rule details"
                            placeholder="Details"
                            maxLength={2000}
                            value={constraintForm.data.details}
                            onChange={(event) =>
                                constraintForm.setData(
                                    'details',
                                    event.target.value,
                                )
                            }
                        />
                        <QuestionnaireError>
                            {constraintForm.errors.details}
                        </QuestionnaireError>
                    </QuestionnaireItem>

                    <QuestionnaireItem
                        name="confirmation"
                        required
                        multiple
                        invalid={Boolean(
                            constraintForm.errors.explicitly_confirmed,
                        )}
                    >
                        <QuestionnaireTitle>
                            Review and explicitly confirm
                        </QuestionnaireTitle>
                        <QuestionnaireDescription>
                            Chef records this only after a household member
                            confirms it was explicitly stated.
                        </QuestionnaireDescription>
                        <dl className="grid gap-2 rounded-lg border bg-background p-3 text-sm sm:grid-cols-[7rem_1fr]">
                            <dt className="text-muted-foreground">
                                Applies to
                            </dt>
                            <dd>{selectedOwner}</dd>
                            <dt className="text-muted-foreground">Kind</dt>
                            <dd>{selectedKind}</dd>
                            <dt className="text-muted-foreground">Rule</dt>
                            <dd>
                                {constraintForm.data.subject || 'Not entered'}
                            </dd>
                            {constraintForm.data.details && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Details
                                    </dt>
                                    <dd>{constraintForm.data.details}</dd>
                                </>
                            )}
                        </dl>
                        <QuestionnaireChoices>
                            <QuestionnaireChoice
                                value="confirmed"
                                checked={
                                    constraintForm.data.explicitly_confirmed
                                }
                                onChange={(event) =>
                                    constraintForm.setData(
                                        'explicitly_confirmed',
                                        event.target.checked,
                                    )
                                }
                            >
                                I confirm this is an explicit household safety
                                rule.
                            </QuestionnaireChoice>
                        </QuestionnaireChoices>
                        <QuestionnaireError>
                            {constraintForm.errors.explicitly_confirmed ??
                                'Confirm the rule before recording it.'}
                        </QuestionnaireError>
                    </QuestionnaireItem>

                    <QuestionnaireActions>
                        <QuestionnairePrevious
                            disabled={constraintForm.processing}
                        />
                        <QuestionnaireSkip
                            disabled={constraintForm.processing}
                            onClick={() =>
                                constraintForm.setData('details', '')
                            }
                        />
                        <QuestionnaireNext
                            disabled={constraintForm.processing}
                        />
                        <QuestionnaireSubmit
                            disabled={constraintForm.processing}
                        >
                            {constraintForm.processing ? (
                                <LoaderCircle className="animate-spin" />
                            ) : (
                                <ShieldCheck />
                            )}
                            Record rule
                        </QuestionnaireSubmit>
                    </QuestionnaireActions>
                </Questionnaire>
            ) : (
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setAddingConstraint(true)}
                    >
                        <Plus /> Add safety rule
                    </Button>
                    {safetyReviewRequired && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={safetyReviewForm.processing}
                            onClick={() =>
                                safetyReviewForm.post(
                                    storeSafetyReview.url(planId),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {safetyReviewForm.processing ? (
                                <LoaderCircle className="animate-spin" />
                            ) : (
                                <ShieldCheck />
                            )}
                            {constraints.length > 0
                                ? 'I reviewed these details'
                                : 'Confirm none reported'}
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

export function HouseholdTruth({
    household,
    planId,
    safetyReviewRequired,
    conversationId,
    onShowMessageSource,
}: {
    household: MealPlanWorkspace['household'];
    planId: number;
    safetyReviewRequired: boolean;
    conversationId: number;
    onShowMessageSource: (messageId: number) => void;
}) {
    const ownerNames = new Map(
        household.people.map((person) => [person.id, person.name]),
    );
    const preferences = [
        ...household.preferences,
        ...household.people.flatMap((person) => person.preferences),
    ];
    const ownerFor = (personId: number | null) =>
        personId === null
            ? household.name
            : (ownerNames.get(personId) ?? 'Household member');
    const preferenceGroups = (items: Preference[], category: string) =>
        Array.from(groupByOwner(items), ([personId, ownerPreferences]) => (
            <TruthOwnerGroup
                key={personId ?? 'household'}
                owner={ownerFor(personId)}
                category={category}
            >
                {ownerPreferences.map((preference) => (
                    <EditablePreference
                        key={preference.id}
                        preference={preference}
                        conversationId={conversationId}
                        onShowMessageSource={onShowMessageSource}
                    />
                ))}
            </TruthOwnerGroup>
        ));

    return (
        <section>
            <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                <UsersRound className="size-4" /> Household truth
            </h2>
            <div className="space-y-4">
                <SafetyRules
                    household={household}
                    planId={planId}
                    safetyReviewRequired={safetyReviewRequired}
                    conversationId={conversationId}
                    onShowMessageSource={onShowMessageSource}
                    embedded
                />
                <TruthGroup
                    title="Stated preferences"
                    empty="Nothing stated yet"
                >
                    {preferenceGroups(
                        preferences.filter(
                            (item) =>
                                item.provenance === 'stated' ||
                                item.provenance === 'feedback',
                        ),
                        'stated preferences',
                    )}
                </TruthGroup>
                <TruthGroup
                    title="Working defaults"
                    empty="No planning defaults yet"
                >
                    {preferenceGroups(
                        preferences.filter(
                            (item) => item.provenance === 'default',
                        ),
                        'working defaults',
                    )}
                </TruthGroup>
                <TruthGroup
                    title="Chef inferences"
                    empty="Chef has not inferred anything yet"
                >
                    {preferenceGroups(
                        preferences.filter(
                            (item) => item.provenance === 'inferred',
                        ),
                        'Chef inferences',
                    )}
                </TruthGroup>
            </div>
            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                Safety rules retain their human source. Inferences stay labelled
                and editable.
            </p>
        </section>
    );
}
