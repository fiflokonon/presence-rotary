<?php

namespace App\Imports;

use App\Models\Member;
use App\Models\Position;
use App\Models\Title;
use Illuminate\Support\Collection as BaseCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MembersImport implements ToCollection, WithHeadingRow
{
    /** @var array<int, array{row: int, message: string}> */
    public array $errors = [];

    public int $imported = 0;

    public function collection(BaseCollection $rows): void
    {
        $titlesByName = Title::pluck('id', 'name');
        $positionsByName = Position::pluck('id', 'name');

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            $email = trim((string) ($row['email'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $club = trim((string) ($row['club'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            $titre = trim((string) ($row['titre'] ?? ''));
            $poste = trim((string) ($row['poste'] ?? ''));
            $classification = trim((string) ($row['classification'] ?? ''));

            if ($email === '' && $name === '' && $club === '' && $phone === '' && $titre === '' && $poste === '' && $classification === '') {
                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = ['row' => $rowNumber, 'message' => 'Email invalide ou manquant.'];

                continue;
            }

            if ($name === '' || $club === '' || $phone === '') {
                $this->errors[] = ['row' => $rowNumber, 'message' => 'Nom, club et téléphone sont obligatoires.'];

                continue;
            }

            if (! $titlesByName->has($titre)) {
                $this->errors[] = ['row' => $rowNumber, 'message' => "Organisation inconnue : \"{$titre}\"."];

                continue;
            }

            if ($poste !== '' && ! $positionsByName->has($poste)) {
                $this->errors[] = ['row' => $rowNumber, 'message' => "Titre/qualité inconnu : \"{$poste}\"."];

                continue;
            }

            $attributes = [
                'name' => $name,
                'club' => $club,
                'phone' => $phone,
                'title_id' => $titlesByName[$titre],
                'is_club_member' => true,
            ];

            if ($classification !== '') {
                $attributes['classification'] = $classification;
            }

            if ($poste !== '') {
                $attributes['position_id'] = $positionsByName[$poste];
            }

            Member::updateOrCreate(['email' => Member::normalizeEmail($email)], $attributes);

            $this->imported++;
        }
    }
}
