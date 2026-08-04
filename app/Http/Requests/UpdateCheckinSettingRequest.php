<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCheckinSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_guest_option' => $this->boolean('show_guest_option'),
            'show_club_field_for_guests' => $this->boolean('show_club_field_for_guests'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'show_guest_option' => ['boolean'],
            'show_club_field_for_guests' => ['boolean'],
        ];
    }
}
