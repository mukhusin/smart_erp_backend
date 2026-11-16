<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emp_allowances', function (Blueprint $table) {
            /** A monthly allowance is paid until HR stops it (as in HRMS, the end date does not stop it); paid up to this day. */
            $table->date('stopped_at')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('emp_allowances', fn (Blueprint $table) => $table->dropColumn('stopped_at'));
    }
};
