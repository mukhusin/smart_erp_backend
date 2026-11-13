<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_loans', function (Blueprint $table) {
            $table->date('date_of_loan')->change();
            $table->date('end_date')->nullable()->change();
            $table->decimal('amount', 14, 2)->default(0)->change();
            $table->decimal('instalment', 14, 2)->default(0)->change();
            $table->decimal('returned', 14, 2)->default(0)->change();
            $table->decimal('remain', 14, 2)->default(0)->change();
            $table->string('description', 500)->nullable()->after('status');
            $table->foreignId('requested_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->string('decision_comment', 500)->nullable()->after('decided_at');
        });

        Schema::table('employee_monthly_advances', function (Blueprint $table) {
            $table->decimal('amount', 14, 2)->change();
            $table->string('reason', 500)->nullable()->after('amount');
            $table->string('source', 20)->default('hr')->after('status');
            $table->foreignId('requested_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->string('decision_comment', 500)->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('employee_monthly_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['reason', 'source', 'decided_at', 'decision_comment']);
        });
        Schema::table('employee_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['description', 'decided_at', 'decision_comment']);
        });
    }
};
