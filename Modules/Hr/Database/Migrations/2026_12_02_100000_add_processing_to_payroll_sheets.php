<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_sheets', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable();
            $table->text('processing_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_sheets', fn (Blueprint $t) => $t->dropColumn(['processing_started_at', 'processing_error']));
    }
};
