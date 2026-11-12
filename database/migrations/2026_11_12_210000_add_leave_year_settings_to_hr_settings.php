<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('leave_year_start_month')->default(1);
            $table->boolean('annual_cycle_from_joining')->default(true);
            $table->date('joiners_from')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_settings', fn (Blueprint $table) => $table->dropColumn(['leave_year_start_month', 'annual_cycle_from_joining', 'joiners_from']));
    }
};
