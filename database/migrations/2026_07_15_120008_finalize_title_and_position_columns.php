<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable(false)->change();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->change();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->change();
        });
    }
};
