<?php

namespace App\Enums;

enum AttendanceTitle: string
{
    case Pdg = 'PDG';
    case Dg = 'DG';
    case Dge = 'DGE';
    case Dgn = 'DGN';
    case Adg = 'AdG';
    case PAdg = 'PAdG';
    case PastPresident = 'Past Président';
    case President = 'Président';
    case PresidentElu = 'Président Elu';
    case PresidentNomme = 'Président Nommé';
    case Secretaire = 'Secrétaire';
    case Tresorier = 'Trésorier';
    case Protocole = 'Protocole';
    case PresidentDeCommission = 'Président de Commission';
    case Rotarien = 'Rotarien';
    case Rotaractien = 'Rotaractien';
    case Invite = 'Invité';

    public function category(): AttendanceCategory
    {
        return match ($this) {
            self::Pdg, self::Dg, self::Dge, self::Dgn, self::Adg, self::PAdg,
            self::PastPresident, self::President, self::PresidentElu, self::PresidentNomme,
            self::Secretaire, self::Tresorier, self::Protocole, self::PresidentDeCommission => AttendanceCategory::Officials,
            self::Rotarien => AttendanceCategory::Members,
            self::Rotaractien => AttendanceCategory::Rotaractors,
            self::Invite => AttendanceCategory::Guests,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
