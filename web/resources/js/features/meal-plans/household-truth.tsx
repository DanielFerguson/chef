import { router } from '@inertiajs/react';
import { Check, Pencil, ShieldCheck, Trash2, UsersRound } from 'lucide-react';
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
            className="ml-auto flex shrink-0 items-center rounded-md bg-background/95 pl-1 opacity-100 transition-opacity md:[@media(hover:hover)]:pointer-events-none md:[@media(hover:hover)]:absolute md:[@media(hover:hover)]:right-0 md:[@media(hover:hover)]:opacity-0 md:[@media(hover:hover)]:group-focus-within:pointer-events-auto md:[@media(hover:hover)]:group-focus-within:opacity-100 md:[@media(hover:hover)]:group-hover:pointer-events-auto md:[@media(hover:hover)]:group-hover:opacity-100"
        >
            {children}
        </div>
    );
}

function EditablePreference({ preference }: { preference: Preference }) {
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

function EditableConstraint({ constraint }: { constraint: Constraint }) {
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
            {draft === null && source && (
                <p className="mt-1 ml-6 line-clamp-2 text-xs leading-5 text-muted-foreground">
                    Confirmed by {source.author?.name ?? 'a household member'}:
                    “{source.content}”
                </p>
            )}
        </li>
    );
}

export function HouseholdTruth({
    household,
}: {
    household: MealPlanWorkspace['household'];
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
                    empty="No confirmed allergies or safety rules"
                >
                    {constraintGroups}
                </TruthGroup>
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
