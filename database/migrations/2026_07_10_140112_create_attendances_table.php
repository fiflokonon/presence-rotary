<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_session_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('name');
            $table->string('club');
            $table->string('phone');
            $table->string('classification')->nullable();
            $table->string('email')->nullable();
            $table->boolean('present')->default(true);
            $table->boolean('is_late')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
