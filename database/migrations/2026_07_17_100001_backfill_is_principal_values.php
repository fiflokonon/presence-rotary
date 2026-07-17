<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('titles')->whereIn('name', ['Rotary', 'Rotaract'])->update(['is_principal' => true]);
    }

    public function down(): void
    {
        DB::table('titles')->whereIn('name', ['Rotary', 'Rotaract'])->update(['is_principal' => false]);
    }
};
