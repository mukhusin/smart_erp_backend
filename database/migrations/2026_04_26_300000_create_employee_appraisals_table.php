<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_appraisals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('department_appraisal_id')->constrained('department_appraisals')->onDelete('cascade');
            $table->string('period');                            // e.g. "Q1 2026" or "2026"
            $table->string('status')->default('draft');          // draft|self_assessed|hod_reviewed|completed
            $table->text('employee_comment')->nullable();
            $table->text('hod_comment')->nullable();
            $table->double('total_score')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_appraisals');
    }
};
