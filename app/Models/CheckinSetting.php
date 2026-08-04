<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckinSetting extends Model
{
    protected $fillable = ['show_guest_option', 'show_club_field_for_guests'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_guest_option' => 'boolean',
            'show_club_field_for_guests' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function guestOptionEnabled(): bool
    {
        return static::current()?->show_guest_option ?? true;
    }

    public static function clubFieldEnabledForGuests(): bool
    {
        return static::current()?->show_club_field_for_guests ?? false;
    }
}
