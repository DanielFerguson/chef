<?php

namespace App\Http\Requests;

use App\Models\MealPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMealPlanPurchasePreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $mealPlan = $this->route('mealPlan');

        return $mealPlan instanceof MealPlan
            && $this->user()?->can('update', $mealPlan) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'basket_target_cents' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
