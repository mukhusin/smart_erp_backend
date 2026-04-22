<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('emp_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('supervisor_id')->constrained('employees')->onDelete('cascade');
            $table->date('from_date');
            $table->string('to_date')->default('current');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('emp_supervisors'); }
};
