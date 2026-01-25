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
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
            // We cannot easily make a column nullable in SQLite/MySQL without doctrine/dbal sometimes, 
            // but Laravel 11 usually handles it.
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
