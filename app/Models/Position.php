<?php

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class);
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
}
