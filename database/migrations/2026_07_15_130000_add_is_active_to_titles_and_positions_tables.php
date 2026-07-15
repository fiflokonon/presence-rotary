<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('category');
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
