<?php

use Illuminate\Support\Facades\DB;

it('backfills title_id and position_id for every old AttendanceTitle value', function () {
    $rotaryPositions = [
        'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
        'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
        'Président de Commission',
    ];

    $meetingSessionId = DB::table('meeting_sessions')->insertGetId([
        'title' => 'Réunion test', 'date' => '2026-01-01', 'time' => '18:00',
        'is_active' => false, 'is_open' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([...$rotaryPositions, 'Rotarien', 'Rotaractien', 'Invité'] as $oldValue) {
        DB::table('attendances')->insert([
            'meeting_session_id' => $meetingSessionId, 'title' => $oldValue, 'name' => $oldValue,
            'club' => 'RC Cotonou Ife', 'phone' => '+229 90 00 00 00', 'present' => true,
            'is_late' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // These rows now have title_id/position_id = null (columns added by the
    // schema migrations from Step 3, which RefreshDatabase already ran).
    // Re-run the backfill migration's logic directly against them.
    $migrationPath = glob(database_path('migrations/*_backfill_title_and_position_ids.php'))[0];
    $migration = include $migrationPath;
    $migration->up();

    foreach ($rotaryPositions as $positionName) {
        $row = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
            ->leftJoin('positions', 'attendances.position_id', '=', 'positions.id')
            ->where('attendances.title', $positionName)
            ->select('titles.name as title_name', 'positions.name as position_name')
            ->sole();

        expect($row->title_name)->toBe('Rotary')->and($row->position_name)->toBe($positionName);
    }

    $rotarien = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
        ->leftJoin('positions', 'attendances.position_id', '=', 'positions.id')
        ->where('attendances.title', 'Rotarien')
        ->select('titles.name as title_name', 'positions.name as position_name')->sole();
    expect($rotarien->title_name)->toBe('Rotary')->and($rotarien->position_name)->toBe('Membre');

    $rotaractien = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
        ->leftJoin('positions', 'attendances.position_id', '=', 'positions.id')
        ->where('attendances.title', 'Rotaractien')
        ->select('titles.name as title_name', 'positions.name as position_name')->sole();
    expect($rotaractien->title_name)->toBe('Rotaract')->and($rotaractien->position_name)->toBe('Membre');

    $invite = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
        ->where('attendances.title', 'Invité')
        ->select('titles.name as title_name', 'attendances.position_id')->sole();
    expect($invite->title_name)->toBe('Invité')->and($invite->position_id)->toBeNull();
});
