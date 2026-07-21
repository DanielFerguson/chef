import { router, useForm } from '@inertiajs/react';
import {
    Check,
    MessagesSquare,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    destroy as destroyConstraint,
    update as updateConstraint,
} from '@/actions/App/Http/Controllers/ConstraintController';
import {
    destroy as destroyPreference,
    update as updatePreference,
} from '@/actions/App/Http/Controllers/PreferenceController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    const constraints = [
        ...household.constraints,
        ...household.people.flatMap((person) => person.constraints),
    ];
    const [addingConstraint, setAddingConstraint] = useState(false);
    const constraintForm = useForm<{
        person_id: number | '';
        kind: string;
        subject: string;
        details: string;
        severity: string;
        explicitly_confirmed: boolean;
    }>({
        person_id: '',
        kind: 'allergy',
        subject: '',
        details: '',
        severity: '',
        explicitly_confirmed: true,
    });
    const safetyReviewForm = useForm({ explicitly_reviewed: true });
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

    return (
        <section>
            <h2 className="mb-3 flex items-center gap-2 text-sm font-medium">
                <UsersRound className="size-4" /> Household truth
            </h2>
            <div className="space-y-4">
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
                <div className="space-y-2 rounded-lg border bg-background p-3">
                    <p className="text-xs leading-5 text-muted-foreground">
                        Safety rules are only recorded from an explicit
                        household statement. Chef never infers allergies from
                        preferences or meal feedback.
                    </p>
                    {addingConstraint ? (
                        <form
                            className="space-y-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                constraintForm.transform((data) => ({
                                    ...data,
                                    person_id: data.person_id || null,
                                    details: data.details.trim() || null,
                                    severity: data.severity.trim() || null,
                                }));
                                constraintForm.post('/constraints', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        constraintForm.reset();
                                        setAddingConstraint(false);
                                    },
                                });
                            }}
                        >
                            <select
                                aria-label="Safety rule applies to"
                                className="h-9 w-full rounded-md border bg-background px-2 text-xs"
                                value={constraintForm.data.person_id}
                                onChange={(event) =>
                                    constraintForm.setData(
                                        'person_id',
                                        event.target.value === ''
                                            ? ''
                                            : Number(event.target.value),
                                    )
                                }
                            >
                                <option value="">Whole household</option>
                                {household.people.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Safety rule type"
                                className="h-9 w-full rounded-md border bg-background px-2 text-xs"
                                value={constraintForm.data.kind}
                                onChange={(event) =>
                                    constraintForm.setData(
                                        'kind',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="allergy">Allergy</option>
                                <option value="medical">Medical</option>
                                <option value="dietary">Dietary</option>
                                <option value="religious">Religious</option>
                                <option value="accessibility">
                                    Accessibility
                                </option>
                                <option value="other">Other</option>
                            </select>
                            <Input
                                aria-label="Safety rule subject"
                                placeholder="e.g. Peanut allergy"
                                value={constraintForm.data.subject}
                                onChange={(event) =>
                                    constraintForm.setData(
                                        'subject',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <Input
                                aria-label="Safety rule details"
                                placeholder="Details or cross-contamination needs"
                                value={constraintForm.data.details}
                                onChange={(event) =>
                                    constraintForm.setData(
                                        'details',
                                        event.target.value,
                                    )
                                }
                            />
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setAddingConstraint(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={constraintForm.processing}
                                >
                                    Record safety rule
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <div className="flex flex-wrap gap-2">
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
                                    disabled={safetyReviewForm.processing}
                                    onClick={() =>
                                        safetyReviewForm.post(
                                            `/meal-plans/${planId}/safety-review`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <ShieldCheck />
                                    {constraints.length > 0
                                        ? 'I reviewed these details'
                                        : 'Confirm none reported'}
                                </Button>
                            )}
                        </div>
                    )}
                </div>
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
