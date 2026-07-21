<?php

namespace App\Http\Controllers;

use App\Actions\Automation\ReleaseRetailerConnectionLease;
use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Enums\BrowserSessionStatus;
use App\Models\BrowserSession;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RetailerConnectionAuthenticationController extends Controller
{
    public function start(
        Request $request,
        RetailerConnection $retailerConnection,
        StartRetailerConnection $start,
    ): RedirectResponse {
        $this->authorize('authenticate', $retailerConnection);
        $session = $start->handle($retailerConnection->team, $request->user());

        return to_route('browser-sessions.authenticate.show', $session);
    }

    public function show(BrowserSession $browserSession): Response|RedirectResponse
    {
        $this->authorize('control', $browserSession);

        if ($browserSession->status !== BrowserSessionStatus::HumanControl) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'This secure Woolworths sign-in session has ended. Reconnect when you are ready to try again.',
            ]);

            return redirect($this->returnUrl($browserSession));
        }

        return Inertia::render('retailer-connections/authenticate', [
            'connection' => [
                'id' => $browserSession->retailer_connection_id,
                'retailer' => 'Woolworths',
                'status' => $browserSession->retailerConnection->status->value,
            ],
            'session' => [
                'id' => $browserSession->id,
                'live_view_endpoint' => route('browser-sessions.live-view', $browserSession),
                'expires_at' => $browserSession->expires_at?->toIso8601String(),
                'timezone' => $browserSession->team->timezone,
                'recording_enabled' => $browserSession->recording_enabled,
            ],
            'return_url' => $this->returnUrl($browserSession),
        ]);
    }

    public function liveView(
        BrowserSession $browserSession,
        BrowserSessionProvider $provider,
        ReleaseRetailerConnectionLease $releaseLease,
    ): JsonResponse {
        $this->authorize('control', $browserSession);

        if ($browserSession->status !== BrowserSessionStatus::HumanControl) {
            abort(410, 'This secure login session is no longer active.');
        }

        try {
            $liveViewUrl = $provider->liveViewUrl($browserSession);
        } catch (BrowserSessionLostException) {
            $browserSession->update([
                'status' => BrowserSessionStatus::Expired,
                'ended_at' => now(),
            ]);
            $releaseLease->handle($browserSession->retailerConnection, 'session:'.$browserSession->id);

            abort(410, 'This secure browser session ended. Return to Shopping to open a new one.');
        }

        return response()->json([
            'live_view_url' => $liveViewUrl,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function verify(
        Request $request,
        BrowserSession $browserSession,
        VerifyRetailerConnection $verify,
    ): RedirectResponse {
        $returnUrl = $this->returnUrl($browserSession);
        $verify->handle($browserSession, $request->user());

        return redirect($returnUrl)->with('success', 'Woolworths is connected. Start cart preparation when you are ready.');
    }

    private function returnUrl(BrowserSession $session): string
    {
        $shoppingListId = $session->metadata['return_shopping_list_id'] ?? null;
        $shoppingList = is_numeric($shoppingListId)
            ? ShoppingList::query()->whereKey((int) $shoppingListId)->where('team_id', $session->team_id)->first()
            : null;

        if ($shoppingList !== null) {
            return route('meal-plans.shopping.show', $shoppingList->meal_plan_id);
        }

        $run = $session->retailerConnection->runs()->latest()->first();

        return $run === null
            ? route('shopping.index')
            : route('meal-plans.shopping.show', $run->shoppingList->meal_plan_id);
    }
}
