<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_monthly_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->double('amount');
            $table->string('date_advanced');
            $table->string('month')->nullable();
            $table->string('year')->nullable();
            $table->string('status')->default('pending'); // pending, deducted
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_monthly_advances');
    }
};
