<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('payroll_sheet_id')->constrained('payroll_sheets')->onDelete('cascade');
            $table->foreignId('payroll_sheet_detail_id')->constrained('payroll_sheet_details')->onDelete('cascade');
            $table->foreignId('employee_loan_id')->nullable()->constrained('employee_loans')->onDelete('set null');
            $table->string('loan_provider');
            $table->string('repayment_date');
            $table->double('repaid_amount')->default(0);
            $table->double('instalment')->default(0);
            $table->double('principle_amount')->default(0);
            $table->double('outstanding_loan')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_histories');
    }
};
