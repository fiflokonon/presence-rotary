<?php

namespace App\Http\Requests;

use App\Models\Title;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTitleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('titles', 'name')->ignore($this->route('title'))],
            'is_principal' => ['boolean', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value) {
                    return;
                }

                $alreadyFlagged = Title::principal()->whereKeyNot($this->route('title'))->count();

                if ($alreadyFlagged >= Title::MAX_PRINCIPAL) {
                    $fail('Maximum '.Title::MAX_PRINCIPAL.' organisations principales — déflaggez-en une avant d\'en ajouter une nouvelle.');
                }
            }],
            'position_ids' => ['array'],
            'position_ids.*' => ['integer', 'exists:positions,id'],
        ];
    }
}
