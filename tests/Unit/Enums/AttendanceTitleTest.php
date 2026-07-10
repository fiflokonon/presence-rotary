<?php

use App\Enums\AttendanceCategory;
use App\Enums\AttendanceTitle;

it('maps official titles to the officials category', function (AttendanceTitle $title) {
    expect($title->category())->toBe(AttendanceCategory::Officials);
})->with([
    AttendanceTitle::Pdg,
    AttendanceTitle::Dg,
    AttendanceTitle::Dge,
    AttendanceTitle::Dgn,
    AttendanceTitle::Adg,
    AttendanceTitle::PAdg,
    AttendanceTitle::PastPresident,
    AttendanceTitle::President,
    AttendanceTitle::PresidentElu,
    AttendanceTitle::PresidentNomme,
    AttendanceTitle::Secretaire,
    AttendanceTitle::Tresorier,
    AttendanceTitle::Protocole,
    AttendanceTitle::PresidentDeCommission,
]);

it('maps Rotarien to the members category', function () {
    expect(AttendanceTitle::Rotarien->category())->toBe(AttendanceCategory::Members);
});

it('maps Rotaractien to the rotaractors category', function () {
    expect(AttendanceTitle::Rotaractien->category())->toBe(AttendanceCategory::Rotaractors);
});

it('maps Invité to the guests category', function () {
    expect(AttendanceTitle::Invite->category())->toBe(AttendanceCategory::Guests);
});

it('lists all title values for the form select', function () {
    expect(AttendanceTitle::values())->toHaveCount(17)
        ->and(AttendanceTitle::values())->toContain('Rotaractien', 'Invité', 'PDG');
});
