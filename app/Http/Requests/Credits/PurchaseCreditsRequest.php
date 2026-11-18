<?php

namespace App\Http\Requests\Credits;

use App\Actions\Credits\PurchaseCredits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Fake "Buy credits": only the fixed packs are allowed, never an arbitrary amount. */
class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route is behind auth; users only ever top up their own balance
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pack_micro_usd' => ['required', 'integer', Rule::in(PurchaseCredits::PACKS_MICRO_USD)],
            // Issued when the page is rendered; makes a double-click or resent POST buy once.
            'client_request_id' => ['required', 'uuid'],
        ];
    }
}
