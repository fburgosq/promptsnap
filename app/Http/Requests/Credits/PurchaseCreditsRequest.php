<?php

namespace App\Http\Requests\Credits;

use App\Services\Credits\CreditLedgerService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'pack_usd' => [
                'required',
                'integer',
                Rule::in(array_keys(CreditLedgerService::PACKS_USD_TO_NANO)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pack_usd.in' => 'Please select a valid credit pack ($5, $10, or $25).',
        ];
    }
}
