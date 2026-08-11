<?php

namespace App\Http\Controllers;

use App\Actions\Baskets\BuildBasketRunView;
use App\Models\BasketRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BasketRunController extends Controller
{
    public function show(
        Request $request,
        BasketRun $basketRun,
        BuildBasketRunView $buildView,
    ): Response|JsonResponse {
        $this->authorize('view', $basketRun);
        $basket = $buildView->handle($basketRun, $request->user());

        return $request->expectsJson()
            ? response()->json(['basket' => $basket])
            : Inertia::render('baskets/show', ['basket' => $basket]);
    }
}
