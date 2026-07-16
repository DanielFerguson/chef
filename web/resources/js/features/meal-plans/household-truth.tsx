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
            <p className="text-xs font-medium text-muted-foreground">{title}</p>
            {children.length > 0 ? (
                <ul>{children}</ul>
            ) : (
                <p className="py-1.5 text-xs text-muted-foreground">{empty}</p>
            )}
        </div>
    );
}

function EditablePreference({
    preference,
    owner,
}: {
    preference: Preference;
    owner: string;
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
        <li className="flex items-center gap-2 py-1.5 text-sm">
            {draft !== null ? (
                <>
                    <Input
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        className="h-8"
                        aria-label="Preference"
                    />
                    <Button size="icon" variant="ghost" onClick={save}>
                        <Check />
                        <span className="sr-only">Save preference</span>
                    </Button>
                </>
            ) : (
                <>
                    <span className="min-w-0 flex-1 truncate">
                        <span className="text-muted-foreground">{owner}: </span>
                        {preference.sentiment === 'dislike'
                            ? 'Avoid '
                            : 'Likes '}
                        {preference.subject}
                    </span>
                    <Badge variant="outline" className="font-normal">
                        {preference.provenance}
                    </Badge>
                    <Button
                        size="icon"
                        variant="ghost"
                        onClick={() => setDraft(preference.subject)}
                    >
                        <Pencil />
                        <span className="sr-only">Edit preference</span>
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
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
                </>
            )}
        </li>
    );
}

function EditableConstraint({
    constraint,
    owner,
}: {
    constraint: Constraint;
    owner: string;
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
        <li className="py-1.5 text-sm">
            <div className="flex items-center gap-2">
                <ShieldCheck className="size-4 shrink-0 text-primary" />
                {draft !== null ? (
                    <>
                        <Input
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            className="h-8"
                            aria-label="Safety rule"
                        />
                        <Button size="icon" variant="ghost" onClick={save}>
                            <Check />
                            <span className="sr-only">Save constraint</span>
                        </Button>
                    </>
                ) : (
                    <>
                        <span className="min-w-0 flex-1 truncate">
                            <span className="text-muted-foreground">
                                {owner}:{' '}
                            </span>
                            {constraint.subject}
                        </span>
                        <Badge variant="outline" className="font-normal">
                            {constraint.kind}
                        </Badge>
                        <Button
                            size="icon"
                            variant="ghost"
                            onClick={() => setDraft(constraint.subject)}
                        >
                            <Pencil />
                            <span className="sr-only">Edit constraint</span>
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            onClick={() =>
                                router.delete(
                                    destroyConstraint.url(constraint.id),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Trash2 />
                            <span className="sr-only">Delete constraint</span>
                        </Button>
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
    const preferenceRows = (items: Preference[]) =>
        items.map((item) => (
            <EditablePreference
                key={item.id}
                preference={item}
                owner={ownerFor(item.person_id)}
            />
        ));

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
                    {constraints.map((item) => (
                        <EditableConstraint
                            key={item.id}
                            constraint={item}
                            owner={ownerFor(item.person_id)}
                        />
                    ))}
                </TruthGroup>
                <TruthGroup
                    title="Stated preferences"
                    empty="Nothing stated yet"
                >
                    {preferenceRows(
                        preferences.filter(
                            (item) =>
                                item.provenance === 'stated' ||
                                item.provenance === 'feedback',
                        ),
                    )}
                </TruthGroup>
                <TruthGroup
                    title="Working defaults"
                    empty="No planning defaults yet"
                >
                    {preferenceRows(
                        preferences.filter(
                            (item) => item.provenance === 'default',
                        ),
                    )}
                </TruthGroup>
                <TruthGroup
                    title="Chef inferences"
                    empty="Chef has not inferred anything yet"
                >
                    {preferenceRows(
                        preferences.filter(
                            (item) => item.provenance === 'inferred',
                        ),
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
