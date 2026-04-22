<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_sheet_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_sheet_id')->constrained('payroll_sheets')->onDelete('cascade');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('cascade');
            $table->foreignId('contract_id')->nullable()->constrained('employee_contracts')->onDelete('set null');
            $table->string('full_name')->nullable();
            $table->string('job_position')->nullable();
            $table->double('basic_salary')->default(0);
            $table->double('actual_basic_salary')->default(0);
            $table->double('total_allowance')->default(0);
            $table->double('total_overtime')->default(0);
            $table->double('allowance_overtime')->default(0);
            $table->double('leave_deduction')->default(0);
            $table->double('attendance_deduction')->default(0);
            $table->double('total_loan')->default(0);
            $table->double('saccos_serving')->default(0);
            $table->double('nssf')->default(0);
            $table->double('nssf_employer')->default(0);
            $table->double('nhif')->default(0);
            $table->double('nhif_employer')->default(0);
            $table->double('heslb')->default(0);
            $table->double('tughe')->default(0);
            $table->double('amount_taxable')->default(0);
            $table->double('paye')->default(0);
            $table->double('ef')->default(0);
            $table->double('nic')->default(0);
            $table->double('relief')->default(0);
            $table->double('gross_salary')->default(0);
            $table->double('total_deduction')->default(0);
            $table->double('net_salary')->default(0);
            $table->json('allowances')->nullable();
            $table->double('wcf')->default(0);
            $table->double('sdl')->default(0);
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('bank_account')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_sheet_details');
    }
};
