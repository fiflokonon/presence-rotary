<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'])]
#[Hidden(['password'])]
class MailSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
