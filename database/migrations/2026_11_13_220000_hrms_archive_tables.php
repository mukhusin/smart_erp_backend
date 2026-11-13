<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_patient_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('patient_id', 100);
            $table->timestamps();
            $table->index(['company_id', 'patient_id']);
        });

        Schema::create('uploaded_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('file_name')->nullable();
            $table->timestamps();
        });

        Schema::create('uploaded_payroll_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_payroll_id')->constrained('uploaded_payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employee_no', 50)->nullable();
            $table->decimal('gross_salary', 15, 2)->nullable();
            $table->decimal('net_pay', 15, 2)->nullable();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_payroll_rows');
        Schema::dropIfExists('uploaded_payrolls');
        Schema::dropIfExists('employee_patient_ids');
    }
};
