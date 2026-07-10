<?php

namespace App\Models;

use Database\Factories\MeetingSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class MeetingSession extends Model
{
    /** @use HasFactory<MeetingSessionFactory> */
    use HasFactory;

    protected $fillable = ['title', 'date', 'time', 'is_open', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_open' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function activate(): void
    {
        DB::transaction(function (): void {
            static::where('is_active', true)->update(['is_active' => false]);
            $this->update(['is_active' => true]);
        });
    }
}
