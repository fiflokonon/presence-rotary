<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'meeting_session_id', 'member_id', 'title_id', 'position_id', 'invited_by', 'name', 'club', 'phone',
        'classification', 'email', 'present', 'is_late', 'has_misc',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'present' => 'boolean',
            'is_late' => 'boolean',
            'has_misc' => 'boolean',
        ];
    }

    public function meetingSession(): BelongsTo
    {
        return $this->belongsTo(MeetingSession::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    protected function groupLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->title->is_principal ? $this->title->name : Title::OTHER_ORGANIZATIONS_LABEL);
    }
}
