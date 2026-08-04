<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignupRequest extends FormRequest
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
            'club_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'plan_id' => ['required', 'integer', 'exists:central.plans,id'],
            'payment_method' => ['required', 'in:mtn_momo,moov_money'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
