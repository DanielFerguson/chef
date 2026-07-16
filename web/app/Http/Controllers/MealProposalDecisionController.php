<?php

namespace App\Http\Controllers;

use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Planning\RejectMealProposal;
use App\Models\MealProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealProposalDecisionController extends Controller
{
    public function accept(Request $request, MealProposal $mealProposal, AcceptMealProposal $accept): RedirectResponse
    {
        $this->authorize('update', $mealProposal);
        $accept->handle($mealProposal, $request->user());

        return back();
    }

    public function reject(Request $request, MealProposal $mealProposal, RejectMealProposal $reject): RedirectResponse
    {
        $this->authorize('update', $mealProposal);
        $reject->handle($mealProposal, $request->user());

        return back();
    }
}
