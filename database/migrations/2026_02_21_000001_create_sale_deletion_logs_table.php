<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('restrict');
            $table->string('invoice_number');
            $table->json('sale_data'); // Full snapshot of the sale record
            $table->json('items_data'); // Full snapshot of sale items
            $table->decimal('total_amount', 15, 2);
            $table->string('customer_name')->nullable();
            $table->foreignId('deleted_by')->constrained('users')->onDelete('restrict');
            $table->text('deletion_reason')->nullable();
            $table->timestamp('original_sale_date')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('branch_id');
            $table->index('invoice_number');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_deletion_logs');
    }
};
