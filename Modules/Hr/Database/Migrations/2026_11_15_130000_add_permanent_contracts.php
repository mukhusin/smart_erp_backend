<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nature_of_contracts', function (Blueprint $table) {
            /** Runs until retirement age: the end date comes from the date of birth, and the employee retires at the end. */
            $table->boolean('is_permanent')->default(false)->after('employment_type');
        });
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('retirement_age')->default(60)->after('hospital_patients');
        });
        DB::table('nature_of_contracts')->where('contract_name', 'like', '%perman%')->update(['is_permanent' => true]);
    }

    public function down(): void
    {
        Schema::table('nature_of_contracts', fn (Blueprint $table) => $table->dropColumn('is_permanent'));
        Schema::table('hr_settings', fn (Blueprint $table) => $table->dropColumn('retirement_age'));
    }
};
