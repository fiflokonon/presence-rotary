<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('club_settings')->insert([
            'name' => 'RC Cotonou Ife',
            'tagline' => 'District 9103',
            'logo_path' => null,
            'primary_color' => '#0B73C5',
            'secondary_color' => '#17A8E5',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('club_settings')->where('name', 'RC Cotonou Ife')->delete();
    }
};
