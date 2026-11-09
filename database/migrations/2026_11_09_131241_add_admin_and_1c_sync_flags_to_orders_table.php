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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('admin_notified_at')->nullable()->after('delivered_at');
            $table->timestamp('synced_1c_at')->nullable()->index()->after('admin_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['synced_1c_at']);
            $table->dropColumn(['admin_notified_at', 'synced_1c_at']);
        });
    }
};
