<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_settings', function (Blueprint $table) {
            $table->boolean('auto_requisition')->default(false)->after('slow_moving_days');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_settings', fn (Blueprint $table) => $table->dropColumn('auto_requisition'));
    }
};
