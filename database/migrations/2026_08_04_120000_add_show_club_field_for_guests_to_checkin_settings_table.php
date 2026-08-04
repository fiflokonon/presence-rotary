<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkin_settings', function (Blueprint $table) {
            $table->boolean('show_club_field_for_guests')->default(false)->after('show_guest_option');
        });
    }

    public function down(): void
    {
        Schema::table('checkin_settings', function (Blueprint $table) {
            $table->dropColumn('show_club_field_for_guests');
        });
    }
};
