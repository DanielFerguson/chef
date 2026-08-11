<?php

namespace App\Actions\Retailers;

use App\Enums\MessageRole;
use App\Models\Message;
use Illuminate\Validation\ValidationException;

class ValidateExplicitRetailerPreferenceEvidence
{
    private const int SUBJECT_VALUE_DISTANCE = 64;

    public function handle(Message $message, string $evidenceQuote): void
    {
        $quote = $this->normalize($evidenceQuote);
        $content = $this->normalize($message->content);

        if ($message->role !== MessageRole::User || $quote === '' || ! str_contains($content, $quote)) {
            throw ValidationException::withMessages([
                'evidence_quote' => 'Quote the exact words in the current user message that ask Chef to remember this grocery preference.',
            ]);
        }

        if (preg_match('/\b(always|prefer(?:red|s|ring)?|next\s+time)\b/u', $quote) !== 1) {
            throw ValidationException::withMessages([
                'evidence_quote' => 'Only save a durable grocery preference after explicit language such as always, prefer, or next time.',
            ]);
        }
    }

    /** @param list<string> $subjectTerms */
    public function handleForSubject(
        Message $message,
        string $evidenceQuote,
        array $subjectTerms,
        string $subject,
    ): void {
        $this->handle($message, $evidenceQuote);
        $quote = $this->normalize($evidenceQuote);
        $mentionsSubject = collect($subjectTerms)->contains(
            fn (string $term): bool => str_contains($quote, $this->normalize($term)),
        );

        if (! $mentionsSubject) {
            throw ValidationException::withMessages([
                'evidence_quote' => "Quote the exact words that explicitly describe the {$subject} preference.",
            ]);
        }
    }

    /**
     * @param  list<string>  $subjectTerms
     * @param  list<string>  $valueTerms
     */
    public function handleForSubjectValue(
        Message $message,
        string $evidenceQuote,
        array $subjectTerms,
        array $valueTerms,
        string $subject,
    ): void {
        $this->handleForSubject($message, $evidenceQuote, $subjectTerms, $subject);
        $quote = $this->normalize($evidenceQuote);

        if (! $this->hasNearbyTerms($quote, $subjectTerms, $valueTerms)) {
            throw ValidationException::withMessages([
                'evidence_quote' => "Quote the exact words that explicitly describe the requested {$subject} value.",
            ]);
        }
    }

    /** @param list<string> $terms */
    public function handleForEveryTerm(
        Message $message,
        string $evidenceQuote,
        array $terms,
        string $subject,
    ): void {
        $this->handle($message, $evidenceQuote);
        $quote = $this->normalize($evidenceQuote);
        $missingTerm = collect($terms)->first(
            fn (string $term): bool => ! str_contains($quote, $this->normalize($term)),
        );

        if ($missingTerm !== null) {
            throw ValidationException::withMessages([
                'evidence_quote' => "Quote the exact words that explicitly name every requested {$subject}.",
            ]);
        }
    }

    public function handleForAmountCents(
        Message $message,
        string $evidenceQuote,
        int $amountCents,
        string $subject,
    ): void {
        $this->handleForSubject(
            $message,
            $evidenceQuote,
            ['basket target', 'budget', 'spend', '$'],
            $subject,
        );
        $quote = $this->normalize($evidenceQuote);

        if (! in_array($amountCents, $this->currencyAmountsInCents($quote), true)) {
            throw ValidationException::withMessages([
                'evidence_quote' => "Quote the exact words that explicitly state the requested {$subject} amount.",
            ]);
        }
    }

    /**
     * @param  list<string>  $subjectTerms
     * @param  list<string>  $valueTerms
     */
    private function hasNearbyTerms(string $quote, array $subjectTerms, array $valueTerms): bool
    {
        $clauses = preg_split(
            '/(?:[.!?;,]+|\b(?:and|but|while|whereas|however|then)\b)/u',
            $quote,
            flags: PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        foreach ($clauses as $clause) {
            foreach ($subjectTerms as $subjectTerm) {
                $normalizedSubject = $this->normalize($subjectTerm);
                $subjectOffset = mb_strpos($clause, $normalizedSubject);

                while ($subjectOffset !== false) {
                    foreach ($valueTerms as $valueTerm) {
                        $normalizedValue = $this->normalize($valueTerm);
                        $valueOffset = mb_strpos($clause, $normalizedValue);

                        while ($valueOffset !== false) {
                            if (abs($subjectOffset - $valueOffset) <= self::SUBJECT_VALUE_DISTANCE) {
                                return true;
                            }

                            $valueOffset = mb_strpos(
                                $clause,
                                $normalizedValue,
                                $valueOffset + mb_strlen($normalizedValue),
                            );
                        }
                    }

                    $subjectOffset = mb_strpos(
                        $clause,
                        $normalizedSubject,
                        $subjectOffset + mb_strlen($normalizedSubject),
                    );
                }
            }
        }

        return false;
    }

    /** @return list<int> */
    private function currencyAmountsInCents(string $quote): array
    {
        $amounts = [];
        preg_match_all('/\$\s*(\d[\d,]*(?:\.\d{1,2})?)/u', $quote, $dollarMatches);
        foreach ($dollarMatches[1] as $amount) {
            $amounts[] = (int) round((float) str_replace(',', '', $amount) * 100);
        }

        preg_match_all('/\b(\d[\d,]*(?:\.\d{1,2})?)\s*(?:aud|dollars?)\b/u', $quote, $wordMatches);
        foreach ($wordMatches[1] as $amount) {
            $amounts[] = (int) round((float) str_replace(',', '', $amount) * 100);
        }

        preg_match_all('/\b(\d[\d,]*)\s*cents?\b/u', $quote, $centMatches);
        foreach ($centMatches[1] as $amount) {
            $amounts[] = (int) str_replace(',', '', $amount);
        }

        return array_values(array_unique($amounts));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
