<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_stocks', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 15, 2)->default(0)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('branch_stocks', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });
    }
};
