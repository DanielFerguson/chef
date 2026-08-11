<?php

namespace App\Ai\Tools;

use App\Actions\Retailers\RememberRetailerProductPreference;
use App\Actions\Retailers\ValidateExplicitRetailerPreferenceEvidence;
use App\Models\BasketRun;
use App\Models\BasketRunItem;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\RetailerProductCandidate;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SaveRetailerProductPreference implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly RememberRetailerProductPreference $rememberPreference,
        private readonly ValidateExplicitRetailerPreferenceEvidence $validateEvidence,
    ) {}

    public function description(): Stringable|string
    {
        return 'Save one still-valid discovered Coles product for future runs without changing the current basket. Call only when the current user explicitly says prefer, always, or next time.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->validateEvidence->handle(
            $this->sourceMessage,
            $request->string('evidence_quote')->toString(),
        );
        $basketRun = BasketRun::query()
            ->where('team_id', $this->mealPlan->team_id)
            ->where('meal_plan_id', $this->mealPlan->id)
            ->findOrFail($request->integer('basket_run_id'));
        $item = BasketRunItem::query()
            ->where('team_id', $this->mealPlan->team_id)
            ->where('basket_run_id', $basketRun->id)
            ->findOrFail($request->integer('basket_run_item_id'));
        $candidate = RetailerProductCandidate::query()
            ->where('team_id', $this->mealPlan->team_id)
            ->findOrFail($request->integer('retailer_product_candidate_id'));
        $candidateTitleWithoutPack = trim((string) preg_replace(
            '/\s+\d+(?:\.\d+)?\s*(?:g|kg|ml|l|pack|pk)(?:\s.*)?$/iu',
            '',
            $candidate->title,
        ));
        $candidateTerms = array_values(collect([
            $candidate->title,
            $candidateTitleWithoutPack,
        ])->filter(fn (?string $term): bool => is_string($term) && trim($term) !== '')
            ->unique()
            ->values()
            ->all());
        $this->validateEvidence->handleForSubject(
            $this->sourceMessage,
            $request->string('evidence_quote')->toString(),
            $candidateTerms,
            'selected-product',
        );
        $preference = $this->rememberPreference->handle(
            $basketRun,
            $item,
            $candidate,
            $this->actor,
            $this->sourceMessage,
        );

        return json_encode([
            'saved' => true,
            'preference' => [
                'id' => $preference->id,
                'ingredient' => $preference->normalized_name,
                'sku' => $preference->sku,
                'product_title' => $preference->product_title,
            ],
            'current_basket_changed' => false,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'basket_run_id' => $schema->integer()->description('Existing basket run identifier.')->required(),
            'basket_run_item_id' => $schema->integer()->description('Existing basket item identifier.')->required(),
            'retailer_product_candidate_id' => $schema->integer()->description('Existing still-valid candidate identifier shown for this item.')->required(),
            'evidence_quote' => $schema->string()->description('Exact words in the current user message containing prefer, always, or next time.')->required(),
        ];
    }
}
