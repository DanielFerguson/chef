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

    public function handle(Conversation $conversation, Message $message, ?int $initialPlanRevision, ?int $initialShoppingRevision = null): string
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

        $shoppingList = $plan?->shoppingList?->refresh();

        if ($shoppingList !== null && $initialShoppingRevision !== null) {
            $shoppingChanges = $shoppingList->revisions()
                ->where('revision', '>', $initialShoppingRevision)
                ->oldest('revision')
                ->pluck('summary');

            if ($shoppingChanges->isNotEmpty()) {
                $parts[] = "Done — I updated the shopping list:\n".$shoppingChanges->map(fn (string $summary) => '- '.$summary)->join("\n");
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

            if ($readiness['recipes_failed'] > 0) {
                $parts[] = 'The completed plan’s recipe batch needs another attempt before the plan is ready.';
            } elseif ($readiness['recipes_preparing'] > 0) {
                $parts[] = 'Chef is preparing every selected recipe together in one batch. You can keep chatting while that finishes.';
            } elseif ($readiness['uncovered_slots'] > 0) {
                $parts[] = $readiness['uncovered_slots'].' meal '.($readiness['uncovered_slots'] === 1 ? 'slot still needs' : 'slots still need').' an option. Tell me what to suggest next.';
            } elseif ($readiness['pending_proposals'] > 0) {
                $parts[] = $readiness['pending_proposals'].' meal '.($readiness['pending_proposals'] === 1 ? 'suggestion is' : 'suggestions are').' ready for review.';
            } elseif ($readiness['safety_review_required']) {
                $parts[] = 'The meals are ready. Review the household safety details in Plan details before confirming; allergies and exclusions are never inferred.';
            } elseif ($readiness['ready_for_confirmation']) {
                $parts[] = "All {$readiness['total_slots']} meal slots are filled. Would you like to review and confirm the plan? Once confirmed, the next step is the shopping list.";
            } elseif ($readiness['confirmed'] && $shoppingList !== null) {
                $parts[] = 'The plan is confirmed and its shopping list is ready to review.';
            } elseif ($readiness['confirmed']) {
                $parts[] = 'The plan is confirmed. The next step is to prepare and review the shopping list.';
            }
        }

        return implode("\n\n", $parts);
    }
}
