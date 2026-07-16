<?php

use App\Models\Title;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Initialize order based on alphabetical order for titles without an order
        $titles = DB::table('titles')
            ->where('name', '!=', Title::GUEST_NAME)
            ->where('order', null)
            ->orderBy('name')
            ->get();

        foreach ($titles as $index => $title) {
            DB::table('titles')->where('id', $title->id)->update(['order' => $index]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reset order to null
        DB::table('titles')
            ->where('name', '!=', Title::GUEST_NAME)
            ->update(['order' => null]);
    }
};
