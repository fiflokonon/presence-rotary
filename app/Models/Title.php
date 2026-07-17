<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use Database\Factories\TitleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    public const GUEST_NAME = 'Invité';

    public const OTHER_ORGANIZATIONS_LABEL = 'Autres organisations';

    public const MAX_PRINCIPAL = 3;

    protected $fillable = ['name', 'category', 'is_principal', 'is_active', 'order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AttendanceCategory::class,
            'is_active' => 'boolean',
            'is_principal' => 'boolean',
        ];
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeActiveOrId(Builder $query, ?int $id): void
    {
        // Grouped in a nested where() — an ungrouped top-level orWhere()
        // here would leak across any other where() clause a caller adds
        // to the same query, due to SQL operator precedence.
        $query->where(function (Builder $q) use ($id) {
            $q->where('is_active', true)->when(
                $id !== null,
                fn (Builder $q2) => $q2->orWhere('id', $id),
            );
        });
    }

    public function scopePrincipal(Builder $query): void
    {
        $query->where('is_principal', true);
    }
}
