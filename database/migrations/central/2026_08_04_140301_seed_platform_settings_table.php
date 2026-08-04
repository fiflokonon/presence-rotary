<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'central';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::connection('central')->table('platform_settings')->insert([
            'default_grace_period_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('central')->table('platform_settings')->truncate();
    }
};
