<?php

namespace App\Http\Controllers;

use App\Actions\Baskets\RequestBasketRestoration;
use App\Models\BasketRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BasketRunRestorationController extends Controller
{
    public function __invoke(
        Request $request,
        BasketRun $basketRun,
        RequestBasketRestoration $requestRestoration,
    ): RedirectResponse|JsonResponse {
        $run = $requestRestoration->handle($basketRun, $request->user());

        return $request->expectsJson()
            ? response()->json(['basket_run' => ['id' => $run->id, 'status' => $run->status->value]])
            : back();
    }
}
