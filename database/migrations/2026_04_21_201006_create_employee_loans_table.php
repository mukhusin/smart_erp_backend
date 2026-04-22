<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('loan_provider_id')->nullable()->constrained('loan_providers')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('date_of_loan');
            $table->double('amount')->default(0);
            $table->double('instalment')->default(0);
            $table->double('returned')->default(0);
            $table->double('remain')->default(0);
            $table->string('end_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('status')->default('enabled');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loans');
    }
};
