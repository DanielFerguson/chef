<?php

namespace App\Notifications;

use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use Illuminate\Notifications\Notification;

class BasketRunStatusNotification extends Notification
{
    public function __construct(public readonly BasketRun $basketRun) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $status = $this->basketRun->status;
        [$title, $message] = match ($status) {
            BasketRunStatus::Ready => [
                'Your Coles basket is ready',
                'Chef verified every selected product and quantity.',
            ],
            BasketRunStatus::NeedsProduct => [
                'Chef needs a valid product',
                'At least one ingredient had no Coles option that passed every required check.',
            ],
            BasketRunStatus::NeedsPlanReview => [
                'Review the revised meal plan',
                $this->basketRun->attention_kind === 'budget_overrun'
                    ? 'The selected products exceeded the basket target. Choose this basket or review one cheaper plan.'
                    : 'Chef prepared one coherent plan change because a required product was unavailable.',
            ],
            BasketRunStatus::ReauthenticationRequired => [
                'Reconnect Coles',
                'The saved Coles session needs the account owner to sign in again.',
            ],
            BasketRunStatus::Failed => [
                'Basket preparation stopped',
                'Chef stopped before it could verify a safe result.',
            ],
            BasketRunStatus::Uncertain, BasketRunStatus::NeedsAttention => [
                'Review the Coles basket',
                'Chef could not confirm the basket state and stopped automation.',
            ],
            default => [
                'Coles basket updated',
                'Open Chef to review the latest basket state.',
            ],
        };

        $productCount = $this->basketRun->items()->count();
        $totalCents = $this->basketRun->retailer_total_cents ?? $this->basketRun->chef_subtotal_cents;
        if ($status === BasketRunStatus::Ready && $totalCents !== null) {
            $message = $productCount.' '.($productCount === 1 ? 'product' : 'products')
                .' · $'.number_format($totalCents / 100, 2).' verified total.';
        }

        return [
            'team_id' => $this->basketRun->team_id,
            'meal_plan_id' => $this->basketRun->meal_plan_id,
            'basket_run_id' => $this->basketRun->id,
            'status' => $status->value,
            'title' => $title,
            'message' => $message,
            'product_count' => $productCount,
            'total_cents' => $totalCents,
        ];
    }
}
