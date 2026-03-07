<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('original_price', 15, 2)->nullable()->after('unit_price');
            $table->boolean('price_adjusted')->default(false)->after('original_price');
            $table->text('adjustment_reason')->nullable()->after('price_adjusted');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['original_price', 'price_adjusted', 'adjustment_reason']);
        });
    }
};
