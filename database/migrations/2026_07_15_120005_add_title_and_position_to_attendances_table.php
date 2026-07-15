<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->after('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->after('title_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('title_id');
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
