<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToggleMeetingSessionOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'send_thank_you_email' => ['nullable', 'boolean'],
            'mention_next_session' => ['nullable', 'boolean'],
            'next_session_option' => ['nullable', 'string'],
            'next_session_date' => ['nullable', 'date'],
        ];
    }
}
