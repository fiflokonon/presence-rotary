<?php

namespace App\Models;

use App\Enums\AttendanceTitle;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = ['title', 'name', 'club', 'phone', 'classification', 'email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => AttendanceTitle::class,
        ];
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
