<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('department_allowance_id')->nullable()->constrained('department_allowances')->onDelete('set null');
            $table->foreignId('allowance_category_id')->nullable()->constrained('allowance_categories')->onDelete('set null');
            $table->foreignId('hod_approved')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('hr_approved')->nullable()->constrained('employees')->onDelete('set null');
            $table->boolean('is_hod_approved')->default(false);
            $table->boolean('is_hr_approved')->default(false);
            $table->string('hod_comment')->nullable();
            $table->string('hr_comment')->nullable();
            $table->string('category');
            $table->string('duration');
            $table->integer('items')->default(1);
            $table->double('amount');
            $table->double('amount_per_item')->nullable();
            $table->string('start_date');
            $table->string('end_date');
            $table->boolean('is_active')->default(false);
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_allowances');
    }
};
