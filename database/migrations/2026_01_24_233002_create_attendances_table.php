<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->time('clock_in');
            $table->time('clock_out')->nullable();
            $table->enum('status', ['present', 'sick', 'leave', 'alpha'])->default('present');
            $table->text('notes')->nullable();
            $table->string('location_lat')->nullable();
            $table->string('location_long')->nullable();
            $table->string('photo_url')->nullable(); // For selfie attendance
            $table->timestamps();

            // Prevent duplicate attendance for same user on same day
            $table->unique(['tenant_id', 'user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
