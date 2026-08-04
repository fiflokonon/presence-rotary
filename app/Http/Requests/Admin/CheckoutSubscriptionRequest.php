<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:central.plans,id'],
            'payment_method' => ['required', 'in:mtn_momo,moov_money'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
