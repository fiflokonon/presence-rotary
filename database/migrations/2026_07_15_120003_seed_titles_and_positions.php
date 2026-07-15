<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $titles = [
            ['name' => 'Rotary', 'category' => 'members'],
            ['name' => 'Rotaract', 'category' => 'rotaractors'],
            ['name' => 'JCI', 'category' => 'guests'],
            ['name' => 'Lions', 'category' => 'guests'],
            ['name' => 'Inner Wheel', 'category' => 'guests'],
            ['name' => 'RRD', 'category' => 'guests'],
            ['name' => 'Invité', 'category' => 'guests'],
        ];

        $titleIds = [];
        foreach ($titles as $title) {
            $titleIds[$title['name']] = DB::table('titles')->insertGetId([
                ...$title,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $positionNames = [
            'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
            'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
            'Président de Commission', 'Vice-Président', 'Membre',
        ];

        $positionIds = [];
        foreach ($positionNames as $name) {
            $positionIds[$name] = DB::table('positions')->insertGetId([
                'name' => $name,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $genericOfficerPositions = ['Président', 'Vice-Président', 'Secrétaire', 'Trésorier', 'Membre'];

        $pivot = [
            ...array_map(
                fn (string $name) => ['title_id' => $titleIds['Rotary'], 'position_id' => $positionIds[$name]],
                $positionNames,
            ),
        ];

        foreach (['Rotaract', 'JCI', 'Lions', 'Inner Wheel', 'RRD'] as $titleName) {
            foreach ($genericOfficerPositions as $positionName) {
                $pivot[] = ['title_id' => $titleIds[$titleName], 'position_id' => $positionIds[$positionName]];
            }
        }

        DB::table('position_title')->insert($pivot);
    }

    public function down(): void
    {
        DB::table('position_title')->truncate();
        DB::table('positions')->truncate();
        DB::table('titles')->truncate();
    }
};
