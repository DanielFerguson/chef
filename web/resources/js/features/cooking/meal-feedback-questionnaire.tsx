import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { update as updateMealFeedback } from '@/actions/App/Http/Controllers/MealFeedbackController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Questionnaire,
    QuestionnaireActions,
    QuestionnaireChoice,
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
import type {
    CookingMeal,
    CookingPerson,
    MealFeedback,
} from '@/features/cooking/types';

const FEEDBACK_ITEMS = [
    {
        name: 'portion',
        choices: [
            { value: 'too_small' },
            { value: 'right' },
            { value: 'too_large' },
        ],
    },
    {
        name: 'effort',
        choices: [{ value: 'easy' }, { value: 'right' }, { value: 'too_much' }],
    },
    {
        name: 'cost',
        choices: [
            { value: 'good_value' },
            { value: 'right' },
            { value: 'too_high' },
        ],
    },
    {
        name: 'leftovers',
        choices: [{ value: 'none' }, { value: 'some' }, { value: 'plenty' }],
    },
    { name: 'details' },
] as const;

type FeedbackItem = (typeof FEEDBACK_ITEMS)[number]['name'];
type FeedbackRating = MealFeedback['rating'];

type FeedbackFormData = {
    rating: FeedbackRating;
    portion: string;
    effort: string;
    cost: string;
    leftovers: string;
    notes: string;
    recipe_adjustment: string;
};

const ratingLabels: Record<FeedbackRating, string> = {
    dislike: 'Dislike',
    neutral: 'Neutral',
    like: 'Like',
    favourite: 'Favourite',
};

const detailItemForError: Partial<
    Record<keyof FeedbackFormData, FeedbackItem>
> = {
    portion: 'portion',
    effort: 'effort',
    cost: 'cost',
    leftovers: 'leftovers',
    notes: 'details',
    recipe_adjustment: 'details',
};

function normalizedFeedback(data: FeedbackFormData) {
    return {
        ...data,
        portion: data.portion || null,
        effort: data.effort || null,
        cost: data.cost || null,
        leftovers: data.leftovers || null,
        notes: data.notes.trim() || null,
        recipe_adjustment: data.recipe_adjustment.trim() || null,
    };
}

function ChoiceQuestion({
    description,
    error,
    name,
    onChange,
    options,
    title,
    value,
}: {
    description: string;
    error?: string;
    name: FeedbackItem;
    onChange: (value: string) => void;
    options: { label: string; value: string }[];
    title: string;
    value: string;
}) {
    return (
        <QuestionnaireItem name={name} invalid={Boolean(error)}>
            <QuestionnaireTitle>{title}</QuestionnaireTitle>
            <QuestionnaireDescription>{description}</QuestionnaireDescription>
            <QuestionnaireChoices>
                {options.map((option) => (
                    <QuestionnaireChoice
                        key={option.value}
                        value={option.value}
                        checked={value === option.value}
                        onChange={(event) => {
                            if (event.target.checked) {
                                onChange(option.value);
                            }
                        }}
                    >
                        {option.label}
                    </QuestionnaireChoice>
                ))}
            </QuestionnaireChoices>
            <QuestionnaireError>{error}</QuestionnaireError>
        </QuestionnaireItem>
    );
}

export function MealFeedbackQuestionnaire({
    meal,
    person,
    existing,
}: {
    meal: CookingMeal;
    person: CookingPerson;
    existing?: MealFeedback;
}) {
    const outcome = meal.outcome;
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [currentItem, setCurrentItem] = useState<FeedbackItem>('portion');
    const [hasSavedFeedback, setHasSavedFeedback] = useState(
        existing !== undefined,
    );
    const form = useForm<FeedbackFormData>({
        rating: existing?.rating ?? 'neutral',
        portion: existing?.portion ?? '',
        effort: existing?.effort ?? '',
        cost: existing?.cost ?? '',
        leftovers: existing?.leftovers ?? '',
        notes: existing?.notes ?? '',
        recipe_adjustment: existing?.recipe_adjustment ?? '',
    });

    if (!outcome) {
        return null;
    }

    const feedbackUrl = updateMealFeedback.url({
        mealOutcome: outcome.id,
        person: person.id,
    });

    const showFirstError = (
        errors: Partial<Record<keyof FeedbackFormData, string>>,
    ) => {
        const errorField = Object.keys(detailItemForError).find(
            (field) => errors[field as keyof FeedbackFormData],
        ) as keyof FeedbackFormData | undefined;
        const errorItem = errorField
            ? detailItemForError[errorField]
            : undefined;

        if (errorItem) {
            setCurrentItem(errorItem);
            setDetailsOpen(true);
        }
    };

    const saveRating = (rating: FeedbackRating) => {
        if (form.processing) {
            return;
        }

        form.clearErrors();
        form.setData('rating', rating);
        form.transform((data) =>
            normalizedFeedback({
                ...data,
                rating,
            }),
        );
        form.put(feedbackUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setHasSavedFeedback(true);
                setCurrentItem('portion');
                setDetailsOpen(true);
            },
            onError: showFirstError,
        });
    };

    const saveDetails = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.clearErrors();
        form.transform(normalizedFeedback);
        form.put(feedbackUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setHasSavedFeedback(true);
                setDetailsOpen(false);
            },
            onError: showFirstError,
        });
    };

    const skipCurrentItem = () => {
        if (currentItem !== 'details') {
            form.setData(currentItem, '');
        }
    };

    return (
        <section
            className="border-t py-6 first:border-t-0 first:pt-0"
            data-feedback-person={person.id}
        >
            <div className="flex items-center justify-between gap-3">
                <h3 className="font-medium">{person.name}</h3>
                {hasSavedFeedback && (
                    <Badge variant="secondary" aria-live="polite">
                        Feedback saved
                    </Badge>
                )}
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
                Choose one rating. It saves immediately.
            </p>
            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                {(Object.keys(ratingLabels) as FeedbackRating[]).map(
                    (rating) => (
                        <Button
                            key={rating}
                            type="button"
                            variant={
                                form.data.rating === rating
                                    ? 'secondary'
                                    : 'outline'
                            }
                            className="h-auto min-h-11 px-2 aria-pressed:ring-1 aria-pressed:ring-foreground/20"
                            aria-label={`${rating} for ${person.name}`}
                            aria-pressed={form.data.rating === rating}
                            disabled={form.processing}
                            onClick={() => saveRating(rating)}
                        >
                            {form.processing && form.data.rating === rating ? (
                                <LoaderCircle className="animate-spin" />
                            ) : null}
                            {ratingLabels[rating]}
                        </Button>
                    ),
                )}
            </div>
            {form.errors.rating && (
                <p className="mt-2 text-sm text-destructive" role="alert">
                    {form.errors.rating}
                </p>
            )}

            {!detailsOpen && hasSavedFeedback && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="mt-3"
                    onClick={() => setDetailsOpen(true)}
                >
                    Add or edit details
                </Button>
            )}

            {detailsOpen && (
                <Questionnaire
                    className="mt-5 rounded-xl border bg-muted/20 p-4 sm:p-5"
                    items={FEEDBACK_ITEMS}
                    item={currentItem}
                    onItemChange={(item) =>
                        setCurrentItem(item as FeedbackItem)
                    }
                    shortcuts="numbers"
                    onSubmit={saveDetails}
                >
                    <div className="flex items-center justify-between gap-3">
                        <QuestionnaireProgress />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={form.processing}
                            onClick={() => setDetailsOpen(false)}
                        >
                            Finish later
                        </Button>
                    </div>

                    <ChoiceQuestion
                        name="portion"
                        title="How was the portion?"
                        description="Optional and specific to this person."
                        value={form.data.portion}
                        onChange={(value) => form.setData('portion', value)}
                        error={form.errors.portion}
                        options={[
                            { value: 'too_small', label: 'Too small' },
                            { value: 'right', label: 'About right' },
                            { value: 'too_large', label: 'Too large' },
                        ]}
                    />
                    <ChoiceQuestion
                        name="effort"
                        title="How did the cooking effort feel?"
                        description="This helps Chef learn what is practical for your household."
                        value={form.data.effort}
                        onChange={(value) => form.setData('effort', value)}
                        error={form.errors.effort}
                        options={[
                            { value: 'easy', label: 'Easy' },
                            { value: 'right', label: 'About right' },
                            { value: 'too_much', label: 'Too much effort' },
                        ]}
                    />
                    <ChoiceQuestion
                        name="cost"
                        title="How did the cost feel?"
                        description="Use your own sense of value; exact prices are not required."
                        value={form.data.cost}
                        onChange={(value) => form.setData('cost', value)}
                        error={form.errors.cost}
                        options={[
                            { value: 'good_value', label: 'Good value' },
                            { value: 'right', label: 'About right' },
                            { value: 'too_high', label: 'Too expensive' },
                        ]}
                    />
                    <ChoiceQuestion
                        name="leftovers"
                        title="How much was left?"
                        description="Optional person-specific feedback about this meal."
                        value={form.data.leftovers}
                        onChange={(value) => form.setData('leftovers', value)}
                        error={form.errors.leftovers}
                        options={[
                            { value: 'none', label: 'None left' },
                            { value: 'some', label: 'Some left' },
                            { value: 'plenty', label: 'Plenty left' },
                        ]}
                    />
                    <QuestionnaireItem
                        name="details"
                        invalid={Boolean(
                            form.errors.notes || form.errors.recipe_adjustment,
                        )}
                    >
                        <QuestionnaireTitle>
                            Anything else worth remembering?
                        </QuestionnaireTitle>
                        <QuestionnaireDescription>
                            Optional notes may become reviewable preference
                            candidates, never safety rules.
                        </QuestionnaireDescription>
                        <textarea
                            aria-label={`Feedback notes for ${person.name}`}
                            className="min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                            placeholder="What worked or did not?"
                            maxLength={5000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.notes)}
                        />
                        {form.errors.notes && (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {form.errors.notes}
                            </p>
                        )}
                        <QuestionnaireInput
                            aria-label={`Recipe adjustment for ${person.name}`}
                            placeholder="Recipe change to keep next time"
                            maxLength={5000}
                            value={form.data.recipe_adjustment}
                            onChange={(event) =>
                                form.setData(
                                    'recipe_adjustment',
                                    event.target.value,
                                )
                            }
                        />
                        <QuestionnaireError>
                            {form.errors.recipe_adjustment}
                        </QuestionnaireError>
                    </QuestionnaireItem>

                    <QuestionnaireActions>
                        <QuestionnairePrevious disabled={form.processing} />
                        {currentItem !== 'details' && (
                            <QuestionnaireSkip
                                disabled={form.processing}
                                onClick={skipCurrentItem}
                            />
                        )}
                        <QuestionnaireNext disabled={form.processing} />
                        <QuestionnaireSubmit disabled={form.processing}>
                            {form.processing ? (
                                <LoaderCircle className="animate-spin" />
                            ) : null}
                            Save details
                        </QuestionnaireSubmit>
                    </QuestionnaireActions>
                </Questionnaire>
            )}
        </section>
    );
}
