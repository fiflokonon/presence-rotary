<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->boolean('is_principal')->default(false)->after('name');
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->string('category')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->string('category')->nullable(false)->change();
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn('is_principal');
        });
    }
};
