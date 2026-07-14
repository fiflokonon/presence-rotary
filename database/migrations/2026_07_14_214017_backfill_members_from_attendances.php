<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('attendances')
            ->join('meeting_sessions', 'attendances.meeting_session_id', '=', 'meeting_sessions.id')
            ->whereNotNull('attendances.email')
            ->where('attendances.email', '!=', '')
            ->orderBy('meeting_sessions.date')
            ->orderBy('meeting_sessions.time')
            ->select(
                'attendances.id',
                'attendances.title',
                'attendances.name',
                'attendances.club',
                'attendances.phone',
                'attendances.classification',
                'attendances.email',
            )
            ->get()
            ->groupBy(fn ($row) => Str::lower(trim($row->email)));

        foreach ($rows as $normalizedEmail => $group) {
            // Rows are ordered oldest-to-newest per session date, so the
            // last one in each group is that email's most recent attendance.
            $latest = $group->last();

            $memberId = DB::table('members')->insertGetId([
                'title' => $latest->title,
                'name' => $latest->name,
                'club' => $latest->club,
                'phone' => $latest->phone,
                'classification' => $latest->classification,
                'email' => $normalizedEmail,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('attendances')->whereIn('id', $group->pluck('id'))->update(['member_id' => $memberId]);
        }
    }

    public function down(): void
    {
        DB::table('attendances')->update(['member_id' => null]);
        DB::table('members')->truncate();
    }
};
