<?php

namespace App\Http\Controllers;

use App\Actions\Baskets\RetryBasketRun;
use App\Models\BasketRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BasketRunRetryController extends Controller
{
    public function __invoke(
        Request $request,
        BasketRun $basketRun,
        RetryBasketRun $retry,
    ): RedirectResponse|JsonResponse {
        $run = $retry->handle($basketRun, $request->user());

        return $request->expectsJson()
            ? response()->json(['basket_run' => ['id' => $run->id, 'status' => $run->status->value]])
            : back();
    }
}
