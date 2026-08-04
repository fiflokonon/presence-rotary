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
        Schema::connection('central')->create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('default_grace_period_days');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('platform_settings');
    }
};
