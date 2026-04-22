<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('appraisal_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_appraisal_id')->constrained()->cascadeOnDelete();
            $table->string('kpi_title');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('appraisal_kpis'); }
};
