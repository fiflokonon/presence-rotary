<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use App\Enums\AttendanceTitle;
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
        'meeting_session_id', 'member_id', 'title', 'name', 'club', 'phone',
        'classification', 'email', 'present', 'is_late',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => AttendanceTitle::class,
            'present' => 'boolean',
            'is_late' => 'boolean',
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

    protected function category(): Attribute
    {
        return Attribute::get(fn (): AttendanceCategory => $this->title->category());
    }
}
