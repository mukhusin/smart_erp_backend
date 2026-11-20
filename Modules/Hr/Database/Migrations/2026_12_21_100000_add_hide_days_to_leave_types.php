<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', fn (Blueprint $table) => $table->boolean('hide_days')->default(false)->after('fixed_days'));
    }

    public function down(): void
    {
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropColumn('hide_days'));
    }
};
