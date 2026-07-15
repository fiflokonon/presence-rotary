<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
        });
    }
};
