<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->onDelete('set null');
            $table->foreignId('employee_dependant_id')->nullable()->constrained('employee_dependants')->onDelete('set null');
            $table->foreignId('hod_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('hr_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('department_id')->nullable();
            $table->string('start_date');
            $table->string('end_date');
            $table->string('requested_start_date');
            $table->string('requested_end_date');
            $table->integer('days');
            $table->integer('days_requested');
            $table->string('leave_year');
            $table->text('description')->nullable();
            $table->string('hod_remark')->nullable();
            $table->boolean('hod_is_approved')->default(false);
            $table->string('hr_office_remark')->nullable();
            $table->boolean('hr_office_is_approved')->default(false);
            $table->string('report_date')->nullable();
            $table->boolean('is_reported')->default(false);
            $table->string('leave_phone')->nullable();
            $table->string('leave_address')->nullable();
            $table->integer('days_passed')->default(0);
            $table->integer('balance')->default(0);
            $table->boolean('deducted')->default(false);
            $table->double('amount')->default(0);
            $table->boolean('is_active')->default(false);
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_leaves');
    }
};
