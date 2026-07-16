<?php

namespace App\Actions\Households;

use App\Models\Message;
use App\Models\Person;
use App\Models\Team;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ValidatePreferenceEvidence
{
    public function handle(Message $message, Team $team, string $subject, string $evidenceQuote, ?Person $person = null, ?string $personReference = null): void
    {
        $quote = $this->normalize($evidenceQuote);
        $content = $this->normalize($message->content);
        $subject = $this->normalize($subject);

        if ($quote === '' || ! str_contains($content, $quote)) {
            throw ValidationException::withMessages([
                'evidence_quote' => 'Quote the exact words in the current user message that support this preference.',
            ]);
        }

        if ($subject === '' || ! str_contains($quote, $subject)) {
            throw ValidationException::withMessages([
                'subject' => 'The stated preference subject must appear in its evidence quote.',
            ]);
        }

        if ($person === null) {
            return;
        }

        $reference = $this->normalize($personReference ?? '');

        if ($reference === '' || ! str_contains($quote, $reference)) {
            throw ValidationException::withMessages([
                'person_reference' => 'Quote the words that identify who this preference belongs to.',
            ]);
        }

        if ($reference === $this->normalize($person->name) || str_contains($quote, $this->normalize($person->name))) {
            return;
        }

        if (! in_array($reference, ['she', 'her', 'he', 'him', 'they', 'them'], true)) {
            throw ValidationException::withMessages([
                'person_reference' => 'Use the person’s name, or an unambiguous pronoun from the immediate conversation.',
            ]);
        }

        $people = $team->people()->get(['id', 'name']);
        $priorMessages = $message->conversation->messages()
            ->where('id', '<', $message->id)
            ->latest('id')
            ->limit(4)
            ->get();

        foreach ($priorMessages as $priorMessage) {
            $priorContent = $this->normalize($priorMessage->content);
            $mentioned = $people->filter(fn (Person $candidate) => Str::contains($priorContent, $this->normalize($candidate->name)));

            if ($mentioned->isEmpty()) {
                continue;
            }

            if ($mentioned->count() === 1 && $mentioned->sole()->is($person)) {
                return;
            }

            break;
        }

        throw ValidationException::withMessages([
            'person_reference' => 'That pronoun is ambiguous. Ask which family member the preference belongs to.',
        ]);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
