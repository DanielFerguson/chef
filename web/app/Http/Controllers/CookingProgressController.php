<?php

namespace App\Http\Controllers;

use App\Actions\Cooking\UpdateCookingProgress;
use App\Models\MealOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CookingProgressController extends Controller
{
    public function __invoke(Request $request, MealOutcome $mealOutcome, UpdateCookingProgress $updateProgress): RedirectResponse
    {
        $validated = $request->validate(['step' => ['required', 'integer', 'min:1', 'max:65535']]);
        $updateProgress->handle($mealOutcome, $request->user(), $validated['step']);

        return back();
    }
}
