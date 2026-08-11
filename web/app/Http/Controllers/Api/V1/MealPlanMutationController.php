<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\BuildMealPlanWorkspace;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Planning\RejectMealProposal;
use App\Actions\Planning\UpdateMealSlotParticipants;
use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\MealProposal;
use App\Models\MealSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealPlanMutationController extends Controller
{
    public function acceptProposal(
        Request $request,
        MealProposal $mealProposal,
        AcceptMealProposal $accept,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $this->authorize('update', $mealProposal);
        $accept->handle($mealProposal, $request->user());

        return $this->workspace($mealProposal->mealPlan, $request, $buildWorkspace);
    }

    public function rejectProposal(
        Request $request,
        MealProposal $mealProposal,
        RejectMealProposal $reject,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $this->authorize('update', $mealProposal);
        $reject->handle($mealProposal, $request->user());

        return $this->workspace($mealProposal->mealPlan, $request, $buildWorkspace);
    }

    public function updateParticipants(
        Request $request,
        MealSlot $mealSlot,
        UpdateMealSlotParticipants $updateParticipants,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $validated = $request->validate([
            'participants' => ['required', 'array', 'min:1'],
            'participants.*.person_id' => ['required', 'integer'],
            'participants.*.servings' => ['required', 'numeric', 'gt:0', 'max:999'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ]);
        $servings = [];

        foreach ($validated['participants'] as $participant) {
            $servings[(int) $participant['person_id']] = (float) $participant['servings'];
        }
        $updateParticipants->handle(
            $mealSlot,
            $request->user(),
            $servings,
            $validated['expected_revision'] ?? null,
        );

        return $this->workspace($mealSlot->mealPlan, $request, $buildWorkspace);
    }

    public function reviewSafety(
        Request $request,
        MealPlan $mealPlan,
        ReviewMealPlanSafety $reviewSafety,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $this->authorize('update', $mealPlan);
        $request->validate(['explicitly_reviewed' => ['accepted']]);
        $reviewSafety->handle($mealPlan, $request->user());

        return $this->workspace($mealPlan, $request, $buildWorkspace);
    }

    public function approve(
        Request $request,
        MealPlan $mealPlan,
        ApproveMealPlan $approve,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $approve->handle($mealPlan, $request->user());

        return $this->workspace($mealPlan, $request, $buildWorkspace);
    }

    private function workspace(
        MealPlan $mealPlan,
        Request $request,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        return response()->json([
            'workspace' => $buildWorkspace->handle($mealPlan->refresh(), $request->user()),
        ]);
    }
}
