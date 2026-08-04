<?php

namespace App\Http\Requests;

use App\Models\CheckinSetting;
use App\Models\Title;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
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
            'title_id' => ['required', 'integer', 'exists:titles,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id', $this->positionBelongsToTitle()],
            'invited_by' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'club' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->clubRequiredForSubmission())],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'has_misc' => ['nullable', 'boolean'],
        ];
    }

    /**
     * `club` is only optional when the submitted title is "Invité" and the
     * admin has left the guest club field disabled — every other title
     * always requires it, matching the pre-existing behavior.
     */
    private function clubRequiredForSubmission(): bool
    {
        if (CheckinSetting::clubFieldEnabledForGuests()) {
            return true;
        }

        $title = Title::find($this->input('title_id'));

        return $title?->name !== Title::GUEST_NAME;
    }

    /**
     * Marked implicit so it still runs when `position_id` is missing/empty —
     * a plain Closure rule is never "implicit" in Laravel, so it would
     * otherwise be silently skipped by the validator's nullable/presence
     * checks whenever no position is submitted, and the "position required
     * for this title" case below would never fire.
     */
    private function positionBelongsToTitle(): ValidationRule
    {
        return new class($this) implements ValidationRule
        {
            public bool $implicit = true;

            public function __construct(private readonly FormRequest $request) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $title = Title::find($this->request->input('title_id'));

                if ($title === null) {
                    return;
                }

                if ($value === null || $value === '') {
                    if ($title->positions()->where('is_active', true)->exists()) {
                        $fail('Le titre/qualité est obligatoire pour cette organisation.');
                    }

                    return;
                }

                if (! $title->positions()->whereKey($value)->exists()) {
                    $fail('Le titre/qualité sélectionné ne correspond pas à l\'organisation choisie.');
                }
            }
        };
    }
}
