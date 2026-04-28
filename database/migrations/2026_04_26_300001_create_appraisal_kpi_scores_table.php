<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appraisal_kpi_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_appraisal_id')->constrained('employee_appraisals')->onDelete('cascade');
            $table->foreignId('appraisal_kpi_id')->constrained('appraisal_kpis')->onDelete('cascade');
            $table->double('self_score')->nullable();            // employee self-rating
            $table->text('self_comment')->nullable();
            $table->double('agreed_score')->nullable();          // HOD-agreed score
            $table->text('agreed_comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appraisal_kpi_scores');
    }
};
