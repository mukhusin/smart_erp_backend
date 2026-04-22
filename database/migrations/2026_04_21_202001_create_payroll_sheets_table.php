<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->string('month');
            $table->string('year');
            $table->string('payroll_from_date');
            $table->string('payroll_to_date');
            $table->integer('number_of_days')->default(30);
            $table->double('payroll_cost')->default(0);
            $table->boolean('hr_approved')->default(false);
            $table->boolean('payroll_approved')->default(false);
            $table->boolean('director_approved')->default(false);
            $table->string('status')->default('pending');
            $table->foreignId('hr_officer')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('payroll_officer')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('director')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_sheets');
    }
};
