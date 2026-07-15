<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    private function mapping(): array
    {
        $rotaryPositions = [
            'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
            'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
            'Président de Commission',
        ];

        $map = [];
        foreach ($rotaryPositions as $name) {
            $map[$name] = ['Rotary', $name];
        }
        $map['Rotarien'] = ['Rotary', 'Membre'];
        $map['Rotaractien'] = ['Rotaract', 'Membre'];
        $map['Invité'] = ['Invité', null];

        return $map;
    }

    public function up(): void
    {
        $titleIds = DB::table('titles')->pluck('id', 'name');
        $positionIds = DB::table('positions')->pluck('id', 'name');

        foreach ($this->mapping() as $oldValue => [$titleName, $positionName]) {
            $titleId = $titleIds[$titleName];
            $positionId = $positionName !== null ? $positionIds[$positionName] : null;

            foreach (['members', 'attendances'] as $table) {
                DB::table($table)
                    ->where('title', $oldValue)
                    ->update(['title_id' => $titleId, 'position_id' => $positionId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('members')->update(['title_id' => null, 'position_id' => null]);
        DB::table('attendances')->update(['title_id' => null, 'position_id' => null]);
    }
};
