<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('education_level');
            $table->string('institution')->nullable();
            $table->string('program')->nullable();
            $table->string('date_of_start')->nullable();
            $table->string('date_of_end')->nullable();
            $table->string('end_year')->nullable();
            $table->string('certificate')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('employee_qualifications'); }
};
