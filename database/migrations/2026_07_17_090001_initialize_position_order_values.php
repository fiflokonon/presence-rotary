<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $positions = DB::table('positions')
            ->whereNull('order')
            ->orderBy('name')
            ->get();

        foreach ($positions as $index => $position) {
            DB::table('positions')->where('id', $position->id)->update(['order' => $index]);
        }
    }

    public function down(): void
    {
        DB::table('positions')->update(['order' => null]);
    }
};
