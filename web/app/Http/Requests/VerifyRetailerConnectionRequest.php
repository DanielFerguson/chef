<?php

namespace App\Http\Requests;

use App\Models\RetailerConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyRetailerConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $connection = $this->route('retailerConnection');

        return $connection instanceof RetailerConnection
            && $this->user()?->can('update', $connection) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var RetailerConnection $connection */
        $connection = $this->route('retailerConnection');
        $hasStandingGrant = $connection->grants()->whereNull('revoked_at')->exists();

        if ($hasStandingGrant) {
            return [
                'standing_consent' => ['sometimes', 'boolean'],
                'disclosure_version' => ['sometimes', 'string'],
            ];
        }

        return [
            'standing_consent' => ['required', 'accepted'],
            'disclosure_version' => [
                'required',
                'string',
                Rule::in([config('retailer.consent.disclosure_version')]),
            ],
        ];
    }
}
