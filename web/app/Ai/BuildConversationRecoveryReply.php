<?php

namespace App\Ai;

use App\Actions\Planning\AssessMealPlanReadiness;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Preference;
use Illuminate\Support\Carbon;
use RuntimeException;

class BuildConversationRecoveryReply
{
    public function __construct(private readonly AssessMealPlanReadiness $assessReadiness) {}

    public function handle(Conversation $conversation, Message $message, ?int $initialPlanRevision): string
    {
        $parts = [];
        $plan = $conversation->mealPlan?->refresh();

        if ($plan !== null && $initialPlanRevision !== null) {
            $changes = $plan->revisions()
                ->where('revision', '>', $initialPlanRevision)
                ->oldest('revision')
                ->pluck('summary');

            if ($changes->isNotEmpty()) {
                $parts[] = "Done — I completed these plan changes:\n".$changes->map(fn (string $summary) => '- '.$summary)->join("\n");
            }
        }

        $preferences = Preference::query()
            ->active()
            ->with('person')
            ->where('team_id', $conversation->team_id)
            ->where(function ($query) use ($message): void {
                $query->where('source_message_id', $message->id)
                    ->orWhere('correction_message_id', $message->id);
            })
            ->get();

        if ($preferences->isNotEmpty()) {
            $truth = $preferences->map(function (Preference $preference): string {
                $owner = $preference->person_id === null ? 'The family' : $preference->person->name;

                return $owner.' '.($preference->sentiment->value === 'dislike' ? 'avoids ' : 'likes ').$preference->subject;
            });
            $parts[] = "I also updated the household information:\n".$truth->map(fn (string $item) => '- '.$item)->join("\n");
        }

        $proposals = $plan?->proposals()
            ->with('mealSlot')
            ->where('message_id', $message->id)
            ->oldest('id')
            ->get() ?? collect();

        if ($proposals->isNotEmpty()) {
            $suggestions = $proposals->map(function ($proposal): string {
                $date = $proposal->mealSlot === null
                    ? 'Unassigned'
                    : Carbon::parse($proposal->mealSlot->date)->format('D, j M');

                return $date.': '.$proposal->title;
            });
            $parts[] = "I added these meal suggestions for review:\n".$suggestions->map(fn (string $item) => '- '.$item)->join("\n");
        }

        if ($parts === []) {
            throw new RuntimeException('Chef completed without a response or a verifiable structured change.');
        }

        if ($plan !== null) {
            $readiness = $this->assessReadiness->handle($plan);

            if ($readiness['ready_for_approval']) {
                $parts[] = "The complete {$readiness['total_slots']}-meal draft is ready to review as one plan. Approving it will start recipe preparation.";
            } elseif ($readiness['confirmed'] && $readiness['recipes_failed'] > 0) {
                $parts[] = 'The approved plan’s recipe batch needs another attempt.';
            } elseif ($readiness['confirmed'] && $readiness['recipes_preparing'] > 0) {
                $parts[] = 'Chef is preparing every approved recipe together.';
            } elseif ($readiness['uncovered_slots'] > 0) {
                $parts[] = $readiness['uncovered_slots'].' meal '.($readiness['uncovered_slots'] === 1 ? 'slot still needs' : 'slots still need').' an option. Tell me what to suggest next.';
            } elseif ($readiness['pending_proposals'] > 0) {
                $parts[] = $readiness['pending_proposals'].' meal '.($readiness['pending_proposals'] === 1 ? 'suggestion is' : 'suggestions are').' ready for review.';
            } elseif ($readiness['confirmed'] && $readiness['recipes_unresolved'] > 0) {
                $parts[] = 'The plan is approved and ready to prepare its remaining recipes.';
            } elseif ($readiness['confirmed']) {
                $parts[] = 'The plan and its recipes are ready.';
            }
        }

        return implode("\n\n", $parts);
    }
}
