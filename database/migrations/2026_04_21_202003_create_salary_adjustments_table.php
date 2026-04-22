<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('contract_id')->nullable()->constrained('employee_contracts')->onDelete('set null');
            $table->enum('adjustment_type', ['increase', 'decrease']);
            $table->string('current_salary');
            $table->string('percentage');
            $table->string('amount');
            $table->string('new_salary');
            $table->string('reason');
            $table->date('effective_date');
            $table->boolean('hr_approved')->default(false);
            $table->foreignId('hr_approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->dateTime('hr_approved_at')->nullable();
            $table->boolean('finance_approved')->default(false);
            $table->foreignId('finance_approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->dateTime('finance_approved_at')->nullable();
            $table->boolean('director_approved')->default(false);
            $table->foreignId('director_approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->dateTime('director_approved_at')->nullable();
            $table->text('hr_comment')->nullable();
            $table->text('finance_comment')->nullable();
            $table->text('director_comment')->nullable();
            $table->text('description')->nullable();
            $table->string('attached_file_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
    }
};
