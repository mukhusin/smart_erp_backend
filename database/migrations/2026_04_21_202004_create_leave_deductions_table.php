<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('payroll_sheet_detail_id')->nullable()->constrained('payroll_sheet_details')->onDelete('set null');
            $table->foreignId('emp_leave_id')->nullable()->constrained('emp_leaves')->onDelete('set null');
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->onDelete('set null');
            $table->string('leave_name');
            $table->string('due_date');
            $table->double('basic_salary');
            $table->double('amount');
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_deductions');
    }
};
