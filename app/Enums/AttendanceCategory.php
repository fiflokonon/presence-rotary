<?php

namespace App\Enums;

enum AttendanceCategory: string
{
    case Officials = 'officials';
    case Members = 'members';
    case Rotaractors = 'rotaractors';
    case Guests = 'guests';

    public function label(): string
    {
        return match ($this) {
            self::Officials => 'Bureau / Officiels',
            self::Members => 'Membres',
            self::Rotaractors => 'Rotaractiens',
            self::Guests => 'Invités',
        };
    }

    /**
     * @return array{bg: string, accent: string}
     */
    public function colors(): array
    {
        return match ($this) {
            self::Officials => ['bg' => '#EAF1FB', 'accent' => '#17458F'],
            self::Members => ['bg' => '#E7F5F1', 'accent' => '#0E7C66'],
            self::Rotaractors => ['bg' => '#FDF3E2', 'accent' => '#C77700'],
            self::Guests => ['bg' => '#F1EFEA', 'accent' => '#6B6558'],
        };
    }
}
