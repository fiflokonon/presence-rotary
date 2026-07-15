<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = ['title_id', 'position_id', 'name', 'club', 'phone', 'classification', 'email'];

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
