<?php

use App\Models\Attendance;
use App\Models\Member;
use Illuminate\Database\QueryException;

it('creates a member with a unique email', function () {
    Member::factory()->create(['email' => 'jean@example.com']);

    expect(Member::where('email', 'jean@example.com')->count())->toBe(1);
});

it('rejects a duplicate member email at the database level', function () {
    Member::factory()->create(['email' => 'jean@example.com']);

    expect(fn () => Member::factory()->create(['email' => 'jean@example.com']))
        ->toThrow(QueryException::class);
});

it('links an attendance to a member', function () {
    $member = Member::factory()->create();
    $attendance = Attendance::factory()->create(['member_id' => $member->id]);

    expect($attendance->member->is($member))->toBeTrue();
});

it('normalizes an email by trimming and lowercasing it', function () {
    expect(Member::normalizeEmail('  JEAN@Example.com  '))->toBe('jean@example.com');
});
