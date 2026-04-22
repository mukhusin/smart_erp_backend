<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_date_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->integer('from_day')->default(26);
            $table->string('from_month')->default('last');
            $table->integer('to_day')->default(25);
            $table->string('to_month')->default('current');
            $table->double('wcf')->default(0.5);
            $table->double('sdl')->default(3.5);
            $table->double('heslb')->default(15);
            $table->double('paye')->default(18);
            $table->double('income_tax')->default(30);
            $table->double('nhif_employee')->default(3);
            $table->double('nhif_employer')->default(3);
            $table->double('nssf_employer')->default(10);
            $table->double('nssf_employee')->default(10);
            $table->double('emergency_fund')->default(5000);
            $table->boolean('use_overtime')->default(false);
            $table->boolean('use_loan')->default(false);
            $table->boolean('use_allowance')->default(false);
            $table->boolean('use_attendance')->default(false);
            $table->boolean('use_leave_deduction')->default(false);
            $table->boolean('use_deduction')->default(true);
            $table->boolean('is_active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_date_settings');
    }
};
