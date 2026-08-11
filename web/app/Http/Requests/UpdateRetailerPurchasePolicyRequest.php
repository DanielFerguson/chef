<?php

namespace App\Http\Requests;

use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRetailerPurchasePolicyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (array_key_exists('preferred_brands', $this->all()) && $this->input('preferred_brands') === null) {
            $this->merge(['preferred_brands' => []]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->can('update', $team);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'home_brand_preference' => ['required', Rule::enum(RetailerHomeBrandPreference::class)],
            'bulk_preference' => ['required', Rule::enum(RetailerBulkPreference::class)],
            'organic_preference' => ['required', Rule::enum(RetailerOrganicPreference::class)],
            'preferred_brands' => ['present', 'array', 'max:10'],
            'preferred_brands.*' => ['required', 'string', 'max:80'],
            'default_basket_target_cents' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
