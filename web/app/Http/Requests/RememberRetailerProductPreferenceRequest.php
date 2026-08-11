<?php

namespace App\Http\Requests;

use App\Models\BasketRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RememberRetailerProductPreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $basketRun = $this->route('basketRun');

        return $basketRun instanceof BasketRun
            && $this->user()?->can('update', $basketRun->mealPlan) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'retailer_product_candidate_id' => [
                'required',
                'integer',
                Rule::exists('retailer_product_candidates', 'id')
                    ->where('team_id', $this->user()?->current_team_id),
            ],
        ];
    }
}
