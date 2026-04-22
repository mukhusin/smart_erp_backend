<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('section_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('hod_approved')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('hr_approved')->nullable()->constrained('employees')->onDelete('set null');
            $table->boolean('is_hod_approved')->default(false);
            $table->boolean('is_hr_approved')->default(false);
            $table->boolean('is_paid')->default(false);
            $table->integer('hours');
            $table->integer('minutes');
            $table->double('amount_per_minute')->default(0);
            $table->double('amount')->default(0);
            $table->string('task_title');
            $table->string('date_of_task_done');
            $table->string('from_datetime');
            $table->string('to_datetime');
            $table->string('type');
            $table->text('description');
            $table->string('hod_comment')->nullable();
            $table->string('hr_comment')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtimes');
    }
};
