<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_leave_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('roster_id')->constrained('rosters')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('hod_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('hr_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('department_id')->nullable();
            $table->string('start_date');
            $table->string('end_date');
            $table->integer('days');
            $table->string('leave_year')->nullable();
            $table->string('report_date')->nullable();
            $table->boolean('is_reported')->default(false);
            $table->string('hod_remark')->nullable();
            $table->boolean('hod_is_approved')->default(false);
            $table->string('hr_office_remark')->nullable();
            $table->boolean('hr_office_is_approved')->default(false);
            $table->string('description')->nullable();
            $table->string('leave_phone')->nullable();
            $table->string('leave_address')->nullable();
            $table->integer('last_year_balance')->default(0);
            $table->integer('current_balance')->default(0);
            $table->integer('actual_balance')->default(0);
            $table->string('status')->default('not_requested');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_leave_rosters');
    }
};
