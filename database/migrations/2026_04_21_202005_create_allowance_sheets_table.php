<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('payroll_sheet_detail_id')->constrained('payroll_sheet_details')->onDelete('cascade');
            $table->foreignId('emp_allowance_id')->nullable()->constrained('emp_allowances')->onDelete('set null');
            $table->foreignId('daily_allowance_id')->nullable(); // set after daily_allowances table
            $table->string('allowance_name');
            $table->text('comment')->nullable();
            $table->double('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_sheets');
    }
};
