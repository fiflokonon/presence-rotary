<?php

use App\Models\Position;
use App\Models\Title;

it('seeds the starter positions', function () {
    // Sort both sides with the same PHP `sort()` call rather than hand-typing
    // an expected order — PHP's default string comparison is byte-wise, not
    // locale-aware French collation, so accented names don't sort where a
    // human would expect (e.g. "Protocole" sorts before "Président").
    $expected = collect([
        'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
        'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
        'Président de Commission', 'Vice-Président', 'Membre',
    ])->sort()->values()->all();

    expect(Position::pluck('name')->sort()->values()->all())->toBe($expected);
});

it('links Rotary to every seeded position', function () {
    $rotary = Title::where('name', 'Rotary')->sole();

    expect($rotary->positions()->count())->toBe(16);
});

it('links Invité to no positions', function () {
    $invite = Title::where('name', 'Invité')->sole();

    expect($invite->positions()->count())->toBe(0);
});

it('links JCI to the five generic officer positions', function () {
    $jci = Title::where('name', 'JCI')->sole();

    expect($jci->positions()->pluck('name')->sort()->values()->all())
        ->toBe(['Membre', 'Président', 'Secrétaire', 'Trésorier', 'Vice-Président']);
});

it('assigns the seeded positions a hierarchical order matching alphabetical sort', function () {
    $expected = Position::query()->orderBy('name')->pluck('name')->all();

    expect(Position::query()->orderBy('order')->pluck('name')->all())->toBe($expected);
});

it('flags Rotary and Rotaract as principal organisations', function () {
    expect(Title::whereIn('name', ['Rotary', 'Rotaract'])->pluck('is_principal')->all())->toBe([true, true])
        ->and(Title::where('name', 'JCI')->sole()->is_principal)->toBeFalse();
});
