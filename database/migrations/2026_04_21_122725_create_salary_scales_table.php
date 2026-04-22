<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('salary_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('set null');
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions')->onDelete('set null');
            $table->string('name');
            $table->decimal('minimum', 15, 2)->default(0);
            $table->decimal('maximum', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('salary_scales'); }
};
