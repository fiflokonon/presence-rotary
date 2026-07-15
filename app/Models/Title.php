<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use Database\Factories\TitleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'category'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => AttendanceCategory::class];
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class);
    }
}
