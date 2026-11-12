<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_date_settings', function (Blueprint $table) {
            $table->json('tax_bands')->nullable()->after('income_tax');
            $table->boolean('use_saccos')->default(true)->after('use_deduction');
            $table->boolean('use_relief')->default(true)->after('use_saccos');
        });

        Schema::table('payroll_sheets', function (Blueprint $table) {
            $table->date('payroll_from_date')->change();
            $table->date('payroll_to_date')->change();
            $table->string('status')->default('draft')->change();
            $table->foreignId('created_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable()->after('created_by');
            $table->timestamp('submitted_at')->nullable()->after('generated_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->string('decision_comment', 500)->nullable()->after('approved_at');
            $table->unsignedInteger('employees_count')->default(0)->after('decision_comment');
            $table->decimal('gross_total', 16, 2)->default(0)->after('employees_count');
            $table->decimal('deduction_total', 16, 2)->default(0)->after('gross_total');
            $table->decimal('net_total', 16, 2)->default(0)->after('deduction_total');
            $table->decimal('employer_total', 16, 2)->default(0)->after('net_total');
        });

        Schema::table('payroll_sheet_details', function (Blueprint $table) {
            $table->json('lines')->nullable()->after('allowances');
            $table->decimal('days_paid', 6, 2)->nullable()->after('lines');
            $table->decimal('unpaid_days', 6, 2)->default(0)->after('days_paid');
            $table->decimal('advance', 14, 2)->default(0)->after('unpaid_days');
            $table->string('emp_category', 20)->nullable()->after('advance');
        });

        Schema::table('loan_histories', function (Blueprint $table) {
            $table->string('status', 10)->default('pending')->after('outstanding_loan');
        });

        Schema::table('employee_monthly_advances', function (Blueprint $table) {
            $table->date('date_advanced')->change();
            $table->foreignId('payroll_sheet_detail_id')->nullable()->after('status')->constrained('payroll_sheet_details')->nullOnDelete();
        });

        Schema::table('overtime_sheets', function (Blueprint $table) {
            $table->date('date_of_task_done')->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_monthly_advances', fn (Blueprint $table) => $table->dropConstrainedForeignId('payroll_sheet_detail_id'));
        Schema::table('loan_histories', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::table('payroll_sheet_details', fn (Blueprint $table) => $table->dropColumn(['lines', 'days_paid', 'unpaid_days', 'advance', 'emp_category']));
        Schema::table('payroll_sheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['generated_at', 'submitted_at', 'approved_at', 'decision_comment', 'employees_count', 'gross_total', 'deduction_total', 'net_total', 'employer_total']);
        });
        Schema::table('payroll_date_settings', fn (Blueprint $table) => $table->dropColumn(['tax_bands', 'use_saccos', 'use_relief']));
    }
};
