<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $connection = 'central';

    protected $fillable = ['default_grace_period_days'];

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
