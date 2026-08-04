<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGracePeriodRequest extends FormRequest
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
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => ['integer', 'exists:central.tenants,id'],
            'grace_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
