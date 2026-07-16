<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckinSetting extends Model
{
    protected $fillable = ['show_guest_option'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_guest_option' => 'boolean'];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function guestOptionEnabled(): bool
    {
        return static::current()?->show_guest_option ?? true;
    }
}
