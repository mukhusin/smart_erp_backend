<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('start_month');
            $table->unsignedSmallInteger('months')->nullable();
            $table->date('stopped_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('decision_comment')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'status']);
        });

        Schema::table('emp_allowances', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->nullable()->change();
            $table->string('duration')->default('month')->change();
            $table->text('decision_comment')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->string('percentage')->nullable()->change();
            $table->date('effective_date')->nullable()->change();
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('salary_adjustments', fn (Blueprint $table) => $table->string('status', 20)->default('pending')->change());

        Schema::table('payroll_sheet_details', fn (Blueprint $table) => $table->double('other_deduction')->default(0)->after('attendance_deduction'));
    }

    public function down(): void
    {
        Schema::table('payroll_sheet_details', fn (Blueprint $table) => $table->dropColumn('other_deduction'));
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('applied_at');
        });
        Schema::table('emp_allowances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('decision_comment');
        });
        Schema::dropIfExists('other_deductions');
    }
};
