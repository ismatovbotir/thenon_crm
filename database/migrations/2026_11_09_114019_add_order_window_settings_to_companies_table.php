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
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('order_days_ahead')->default(7)->after('notes');
            $table->boolean('next_day_enabled')->default(true)->after('order_days_ahead');
            $table->string('next_day_from', 5)->default('21:00')->after('next_day_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['order_days_ahead', 'next_day_enabled', 'next_day_from']);
        });
    }
};
