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
        Schema::table('location_cutoffs', function (Blueprint $table) {
            $table->boolean('ends_next_day')->default(false)->after('cutoff_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('location_cutoffs', function (Blueprint $table) {
            $table->dropColumn('ends_next_day');
        });
    }
};
