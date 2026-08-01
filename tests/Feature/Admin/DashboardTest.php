<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
});

it('shows an empty state when there are no sessions yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Pas encore de séance');
});

it('shows KPI numbers computed from seeded data', function () {
    Member::factory()->count(5)->create();

    $rotary = Title::where('name', 'Rotary')->sole();

    $olderSession = MeetingSession::factory()->create(['date' => '2026-07-01']);
    Attendance::factory()->for($olderSession)->create(['title_id' => $rotary->id, 'present' => true]);
    Attendance::factory()->for($olderSession)->create(['title_id' => $rotary->id, 'present' => false]);

    $lastSession = MeetingSession::factory()->create(['date' => '2026-07-15']);
    Attendance::factory()->for($lastSession)->create(['title_id' => $rotary->id, 'present' => true]);
    Attendance::factory()->for($lastSession)->create(['title_id' => $rotary->id, 'present' => true]);
    Attendance::factory()->for($lastSession)->create(['title_id' => $rotary->id, 'present' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('5') // membres actifs
        ->assertSee('59 %') // moyenne des taux (50 et 67 arrondis)
        ->assertSee('2/3') // dernière séance : présents/total
        ->assertDontSee('Pas encore de séance');
});
