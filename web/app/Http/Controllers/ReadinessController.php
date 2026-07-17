<?php

namespace App\Http\Controllers;

use App\Support\ApplicationReadiness;
use Illuminate\Http\JsonResponse;

class ReadinessController extends Controller
{
    public function __invoke(ApplicationReadiness $readiness): JsonResponse
    {
        $result = $readiness->inspect();

        return response()->json([
            'status' => $result['ready'] ? 'ready' : 'unavailable',
            'release' => config('app.release'),
        ], $result['ready'] ? 200 : 503);
    }
}
