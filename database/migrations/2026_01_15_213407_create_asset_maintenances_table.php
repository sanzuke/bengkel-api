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
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            $table->string('maintenance_type', 50); // routine, repair, upgrade, inspection
            $table->date('maintenance_date');
            $table->date('completed_date')->nullable();
            $table->string('status', 30)->default('scheduled'); // scheduled, in_progress, completed, cancelled
            $table->string('performed_by', 100)->nullable(); // technician name or vendor
            $table->decimal('cost', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->text('findings')->nullable();
            $table->text('actions_taken')->nullable();
            $table->date('next_maintenance')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['asset_id', 'status']);
            $table->index(['maintenance_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_maintenances');
    }
};
