<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->foreignId('section_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('category_id')->constrained('allowance_categories')->onDelete('cascade');
            $table->foreignId('job_position_id')->constrained('job_positions')->onDelete('cascade');
            $table->foreignId('last_updated_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->double('amount')->default(0);
            $table->double('total_amount')->nullable();
            $table->boolean('rate_payroll_period')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_allowances');
    }
};
