<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_period_days')->nullable()->after('sqlite_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('grace_period_days');
        });
    }
};
